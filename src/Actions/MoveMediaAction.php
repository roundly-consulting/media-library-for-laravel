<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\MediaLibrary\Events\MediaHasBeenMoved;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\Checksum;
use RoundlyConsulting\MediaLibrary\Support\DiskResolver;
use RoundlyConsulting\MediaLibrary\Support\FileTransfer;

/**
 * Relocates a {@see Media} — across disks, and/or to a different owning model and bucket
 * (including model → global and global → model) — moving the original and its generated
 * variants and re-pointing the row. Moving only the variant files is
 * {@see MoveMediaVariantsAction}.
 *
 * The DB update runs inside a transaction; the physical source files are deleted only after the
 * transaction commits, so a rollback never orphans the row from its files.
 *
 * The source original is refcount-guarded (§7.1): when another non-trashed row still shares the
 * `(disk, visibility, checksum)`, the cross-disk move COPIES to the target and re-points this row
 * but leaves the shared source in place. Variants are always per-row, so they relocate freely.
 */
final class MoveMediaAction
{
    public function __construct(
        private readonly DiskResolver $diskResolver,
        private readonly FileTransfer $fileTransfer,
        private readonly Checksum $checksum,
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

        // Resolved before the row is re-pointed: is the SOURCE original still referenced elsewhere?
        $sourceShared = $this->checksum->isSharedByOthers($media);

        $cleanup = $this->relocateFiles($media, $sourceDisk, $targetDisk, $sourceShared);

        DB::transaction(function () use ($media, $toModel, $bucket, $targetDisk, $sourceDisk): void {
            $this->rehome($media, $toModel, $bucket);

            if ($targetDisk !== $sourceDisk) {
                // The original now lives at the same relative path on the target disk.
                $media->path = $this->pathOnTargetDisk($media);
                $media->disk = $targetDisk;
            }

            $media->save();
        });

        // Ordered after commit so a rollback never leaves the row pointing at deleted files.
        foreach ($cleanup as [$cleanupDisk, $cleanupPath]) {
            $this->fileTransfer->delete($cleanupDisk, $cleanupPath);
        }

        event(new MediaHasBeenMoved($media));

        return $media;
    }

    /**
     * Move the original (and any variants that share the original disk) onto the target disk,
     * returning the source files to delete after commit.
     *
     * When the source original is still shared by other rows it is copied but NOT scheduled for
     * deletion — only the last referrer removes it. Variants are always per-row, so they relocate
     * and are cleaned up unconditionally.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function relocateFiles(Media $media, string $sourceDisk, string $targetDisk, bool $sourceShared): array
    {
        if ($sourceDisk === $targetDisk) {
            return [];
        }

        $this->fileTransfer->copy($sourceDisk, $media->getPath(), $targetDisk, $media->getPath(), $media->visibility);

        $cleanup = $sourceShared ? [] : [[$sourceDisk, $media->getPath()]];

        // Variants that lived on the original disk (variants_disk === null) follow the original.
        if ($media->variants_disk === null) {
            foreach ($this->variantPaths($media) as $path) {
                $this->fileTransfer->copy($sourceDisk, $path, $targetDisk, $path, $media->visibility);
                $cleanup[] = [$sourceDisk, $path];
            }
        }

        return $cleanup;
    }

    /** The original's path on the target disk — the same relative path it had on the source disk. */
    private function pathOnTargetDisk(Media $media): string
    {
        return $media->getPath();
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
}
