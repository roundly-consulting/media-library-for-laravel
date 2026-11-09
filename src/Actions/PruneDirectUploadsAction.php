<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Enums\DirectUploadStatus;
use RoundlyConsulting\MediaLibrary\Filesystem\S3UploadSigner;
use RoundlyConsulting\MediaLibrary\Models\MediaUpload;
use RoundlyConsulting\MediaLibrary\Support\MediaConfig;
use RoundlyConsulting\MediaLibrary\Uploads\StoredObjects;
use RoundlyConsulting\MediaLibrary\Uploads\UploadTickets;

/**
 * Clears what direct uploads leave behind: `MediaLibrary::pruneUploads()` / `media:prune-uploads`.
 *
 * - Tickets past `expires_at` + `prune_grace` that were never adopted — pending, or an
 *   `adopting` claim gone stale — become `expired` (one conditional update each, so a concurrent
 *   adoption and the prune never both win) and their object or multipart upload is removed.
 * - Objects under the staging prefix no live ticket holds, older than the grace (a re-sent PUT
 *   after the move, a ticket row lost), and unfinished multipart uploads there, are removed.
 *
 * Returns how many tickets and leftover objects it removed.
 */
final class PruneDirectUploadsAction
{
    public function __construct(
        private readonly UploadTickets $tickets,
        private readonly StoredObjects $objects,
        private readonly S3UploadSigner $s3,
    ) {}

    public function execute(): int
    {
        $cutoff = CarbonImmutable::now()->subMinutes(MediaConfig::uploadPruneGrace());
        $staleClaim = CarbonImmutable::now()->subMinutes(UploadTickets::CLAIM_MINUTES);
        $pruned = 0;

        MediaUpload::query()
            ->where('expires_at', '<', $cutoff)
            ->where(static fn (Builder $query) => $query
                ->where('status', DirectUploadStatus::Pending->value)
                ->orWhere(static fn (Builder $stale) => $stale
                    ->where('status', DirectUploadStatus::Adopting->value)
                    ->where('claimed_at', '<', $staleClaim)))
            ->lazyById()
            ->each(function (MediaUpload $upload) use (&$pruned): void {
                if ($this->tickets->transition($upload, $upload->status, DirectUploadStatus::Expired)) {
                    $this->tickets->discardObject($upload);
                    $pruned++;
                }
            });

        return $pruned + $this->pruneLeftovers($cutoff);
    }

    private function pruneLeftovers(CarbonImmutable $cutoff): int
    {
        $prefix = MediaConfig::stagingPrefix();
        $disks = MediaUpload::query()->distinct()->pluck('disk')->map(strval(...))->all();
        $disks = array_values(array_unique(array_merge($disks, array_filter([MediaConfig::stagingDisk()]))));
        $removed = 0;

        foreach ($disks as $disk) {
            if (! array_key_exists($disk, (array) config('filesystems.disks'))) {
                continue;
            }

            $live = MediaUpload::query()
                ->where('disk', $disk)
                ->whereIn('status', [DirectUploadStatus::Pending->value, DirectUploadStatus::Adopting->value]);

            $held = (clone $live)->pluck('path')->map(strval(...))->all();
            $storage = Storage::disk($disk);

            foreach ($storage->allFiles($prefix) as $path) {
                if (! in_array($path, $held, true) && $storage->lastModified($path) < $cutoff->getTimestamp()) {
                    $this->objects->delete($disk, $path);
                    $removed++;
                }
            }

            $s3 = $this->objects->s3($disk);

            if ($s3 !== null) {
                $keep = (clone $live)->whereNotNull('multipart_upload_id')->pluck('multipart_upload_id')->all();
                $removed += $this->s3->abortStaleMultipartUploads($s3, $prefix, $cutoff, array_values(array_map(strval(...), $keep)));
            }
        }

        return $removed;
    }
}
