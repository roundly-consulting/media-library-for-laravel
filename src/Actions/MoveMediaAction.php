<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\MediaLibrary\Events\MediaHasBeenMoved;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\DiskResolver;
use RoundlyConsulting\MediaLibrary\Support\FileTransfer;

/**
 * Relocates a {@see Media} — across disks, and/or to a different owning model and bucket
 * (including model → global and global → model) — moving the original and its generated
 * variants and re-pointing the row.
 *
 * The DB update runs inside a transaction; the physical source files are deleted only after the
 * transaction commits, so a rollback never orphans the row from its files.
 *
 * Phase 5 will refcount-guard the deletes here (shared deduplicated files survive until their last
 * referrer goes); for now each media owns its files and the source is always removed.
 */
final class MoveMediaAction
{
    public function __construct(
        private readonly DiskResolver $diskResolver,
        private readonly FileTransfer $fileTransfer,
    ) {}

    public function execute(
        Media $media,
        HasMedia|Model|null $toModel = null,
        string $bucket = 'default',
        ?string $disk = null,
    ): Media {
        $targetDisk = $disk ?? $media->disk;
        $this->diskResolver->ensureDiskExists($targetDisk);

        $sourceDisk = $media->disk;
        $sourcePath = $media->getPath();

        $cleanup = $this->relocateFiles($media, $sourceDisk, $targetDisk);

        DB::transaction(function () use ($media, $toModel, $bucket, $targetDisk): void {
            $this->rehome($media, $toModel, $bucket);
            $media->disk = $targetDisk;
            $media->save();
        });

        // Ordered after commit so a rollback never leaves the row pointing at deleted files.
        foreach ($cleanup as [$cleanupDisk, $cleanupPath]) {
            $this->fileTransfer->delete($cleanupDisk, $cleanupPath);
        }

        unset($sourcePath);

        event(new MediaHasBeenMoved($media));

        return $media;
    }

    /** Move only the original's bytes to a new disk, keeping ownership and bucket. */
    public function toDisk(Media $media, string $disk): Media
    {
        return $this->execute($media, $this->ownerOf($media), $media->bucket_name, $disk);
    }

    /** Move only the variant files to a new disk; the original stays put. */
    public function variantsToDisk(Media $media, string $disk): Media
    {
        $this->diskResolver->ensureDiskExists($disk);

        $sourceDisk = $media->variants_disk ?? $media->disk;

        if ($sourceDisk !== $disk) {
            foreach ($this->variantPaths($media) as $path) {
                $this->fileTransfer->move($sourceDisk, $path, $disk, $path, $media->visibility);
            }
        }

        DB::transaction(function () use ($media, $disk): void {
            $media->variants_disk = $disk === $media->disk ? null : $disk;
            $media->save();
        });

        event(new MediaHasBeenMoved($media));

        return $media;
    }

    /**
     * Move the original (and any variants that share the original disk) onto the target disk,
     * returning the source files to delete after commit.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function relocateFiles(Media $media, string $sourceDisk, string $targetDisk): array
    {
        if ($sourceDisk === $targetDisk) {
            return [];
        }

        $this->fileTransfer->copy($sourceDisk, $media->getPath(), $targetDisk, $media->getPath(), $media->visibility);

        $cleanup = [[$sourceDisk, $media->getPath()]];

        // Variants that lived on the original disk (variants_disk === null) follow the original.
        if ($media->variants_disk === null) {
            foreach ($this->variantPaths($media) as $path) {
                $this->fileTransfer->copy($sourceDisk, $path, $targetDisk, $path, $media->visibility);
                $cleanup[] = [$sourceDisk, $path];
            }
        }

        return $cleanup;
    }

    /**
     * @return list<string>
     */
    private function variantPaths(Media $media): array
    {
        $paths = [];

        foreach (array_keys($media->generated_variants ?? []) as $name) {
            $paths[] = $media->getPath((string) $name);
        }

        return $paths;
    }

    private function rehome(Media $media, HasMedia|Model|null $toModel, string $bucket): void
    {
        $media->bucket_name = $bucket;

        if ($toModel instanceof Model) {
            $media->model_type = $toModel->getMorphClass();
            $media->model_id = $toModel->getKey();
        } else {
            $media->model_type = null;
            $media->model_id = null;
        }
    }

    private function ownerOf(Media $media): ?Model
    {
        $owner = $media->model;

        return $owner instanceof Model ? $owner : null;
    }
}
