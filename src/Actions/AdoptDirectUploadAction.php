<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\MediaLibrary\Buckets\PendingFileAddState;
use RoundlyConsulting\MediaLibrary\Enums\DirectUploadMethod;
use RoundlyConsulting\MediaLibrary\Enums\DirectUploadStatus;
use RoundlyConsulting\MediaLibrary\Events\DirectUploadHasBeenAdopted;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadExpired;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadIncomplete;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadNotFound;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Models\MediaUpload;
use RoundlyConsulting\MediaLibrary\Support\MediaConfig;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;
use RoundlyConsulting\MediaLibrary\Support\StoredFiles;
use RoundlyConsulting\MediaLibrary\Uploads\InspectStoredObject;
use RoundlyConsulting\MediaLibrary\Uploads\UploadTickets;
use SensitiveParameter;
use Throwable;

/**
 * Turns a direct upload into media: `MediaLibrary::adoptUpload($token)`.
 *
 * 0. With `$expectedOwner` / `$expectedBucket`, a ticket issued for another owner or bucket is
 *    refused before anything else — never claimed, so the right route can still adopt it.
 * 1. The ticket is claimed with one conditional update, so two requests (or an adoption and a
 *    prune) can never both take it. A second adoption of an adopted ticket returns its media.
 * 2. The stored object is inspected where it lies: size first (over the ticket's cap: rejected),
 *    then the type sniffed from its leading bytes, displayed dimensions, a streamed checksum.
 * 3. It is added like any file — the bucket's current rules, the dedup scope, `singleFile()`,
 *    variants, `MediaHasBeenAdded` — except that the object is moved inside storage instead of
 *    written (or kept where it is for an in-place upload); identical content already stored in
 *    the dedup scope is shared, and the upload deleted once this commits.
 *
 * A refused file is rejected for good: its object is deleted, the ticket records why and
 * `DirectUploadHasBeenRejected` fires. A missing object (not uploaded yet) or any other failure
 * hands the ticket back, so it can be adopted again — a host rollback included, which also moves
 * the object back.
 */
final class AdoptDirectUploadAction
{
    public function __construct(
        private readonly UploadTickets $tickets,
        private readonly InspectStoredObject $inspector,
        private readonly AddMediaAction $addMedia,
        private readonly StoredFiles $files,
    ) {}

    /**
     * @param  (Closure(MediaUpload): void)|null  $beforeInspect  runs under the claim (completes a multipart upload)
     * @param  Model|null  $expectedOwner  refuse a ticket issued for another owner (or for none)
     * @param  string|null  $expectedBucket  refuse a ticket issued for another bucket
     */
    public function execute(
        #[SensitiveParameter] string $token,
        ?Model $actor = null,
        ?Closure $beforeInspect = null,
        ?Model $expectedOwner = null,
        ?string $expectedBucket = null,
    ): Media {
        $upload = $this->tickets->find($token);
        $this->tickets->ensureActor($upload, $actor);
        $this->tickets->ensureTarget($upload, $expectedOwner, $expectedBucket);

        if ($upload->status === DirectUploadStatus::Adopted) {
            return $this->adopted($upload);
        }

        $this->tickets->ensurePending($upload, orAdopting: true);

        if (! $this->tickets->claim($upload)) {
            return $this->unclaimable($upload);
        }

        try {
            if ($beforeInspect !== null) {
                $beforeInspect($upload);
            }

            // One unit: should anything fail once the row is saved (a single-file sweep, a sync
            // variant), the row goes, the object moves back and the ticket can be adopted again.
            $media = $upload->getConnection()->transaction(function () use ($upload): Media {
                $media = $this->adopt($upload);

                $this->tickets->transition($upload, DirectUploadStatus::Adopting, DirectUploadStatus::Adopted, [
                    'media_id' => $media->getKey(),
                    'failure_reason' => null,
                ]);

                return $media;
            });
        } catch (FileUnacceptableForBucket $refused) {
            $this->tickets->reject($upload, $refused->getMessage());

            throw $refused;
        } catch (Throwable $failure) {
            $this->tickets->release($upload);

            throw $failure;
        }

        event(new DirectUploadHasBeenAdopted($media, $upload, $this->files->originalIsShared($media)));

        return $media;
    }

    private function adopt(MediaUpload $upload): Media
    {
        if ($upload->method() === DirectUploadMethod::Multipart && ! $upload->option('completed', false)) {
            throw DirectUploadIncomplete::objectMissing();
        }

        $owner = $this->tickets->owner($upload);

        if ($upload->model_type !== null && $owner === null) {
            throw FileUnacceptableForBucket::ownerGone($upload->bucket_name);
        }

        $size = $this->inspector->size($upload->disk, $upload->path);

        if ($size > $upload->max_size) {
            throw FileUnacceptableForBucket::tooLarge($size, $upload->max_size, $upload->bucket_name);
        }

        $trusted = MediaConfig::trustsStorageChecksums($upload->disk)
            && $upload->declared_checksum !== null
            && $upload->method() === DirectUploadMethod::Put;

        $file = $this->inspector->inspect($upload->disk, $upload->path, $upload->file_name, $upload->in_place, $trusted);

        // A browser that declared a sha256 must have sent exactly those bytes.
        if ($upload->declared_checksum !== null
            && MediaConfig::checksumAlgorithm()->value === 'sha256'
            && $file->object?->checksum !== $upload->declared_checksum) {
            if ($file->isTemporary && is_file($file->path)) {
                @unlink($file->path);
            }

            throw FileUnacceptableForBucket::checksumMismatch($upload->bucket_name);
        }

        $customProperties = $upload->option('custom_properties', []);
        $name = $upload->option('name');
        $queue = $upload->option('queue');

        return $this->addMedia->execute(new PendingFileAddState(
            owner: $owner,
            file: $file,
            bucket: $upload->bucket_name,
            name: is_string($name) ? $name : null,
            fileName: null,
            diskOverride: (string) $upload->option('media_disk', $upload->disk),
            variantsDiskOverride: null,
            visibility: $upload->visibility,
            customProperties: is_array($customProperties) ? $customProperties : [],
            queue: is_string($queue) ? $queue : null,
            draft: $upload->draft,
            uuid: $upload->media_uuid,
        ));
    }

    /** The media an adopted ticket made — a repeated adoption returns it. */
    private function adopted(MediaUpload $upload): Media
    {
        return MediaModel::query()->where('uuid', $upload->media_uuid)->first() ?? throw DirectUploadNotFound::forToken();
    }

    /** Why the claim failed: adopted meanwhile, expired, or another request holds it. */
    private function unclaimable(MediaUpload $upload): Media
    {
        $upload->refresh();

        return match ($upload->status) {
            DirectUploadStatus::Adopted => $this->adopted($upload),
            DirectUploadStatus::Adopting => throw DirectUploadIncomplete::inProgress(),
            DirectUploadStatus::Pending, DirectUploadStatus::Expired => throw DirectUploadExpired::make(),
            default => throw DirectUploadNotFound::forToken(),
        };
    }
}
