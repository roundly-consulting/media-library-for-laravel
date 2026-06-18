<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Observers;

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\Checksum;

/**
 * Cleans up stored files when a media row is permanently removed.
 *
 * Soft deletes intentionally keep the files on disk so a restore is lossless; only a
 * `forceDelete()` removes them.
 *
 * The original is **refcount-guarded** (§7.1): when another non-trashed row still shares the same
 * `(disk, visibility, checksum)`, the deduplicated original is left in place and only this row's
 * own variants are removed. Variants are always per-row (under this row's `{uuid}/variants/`), so
 * they are unconditionally safe to delete.
 */
final class MediaObserver
{
    public function __construct(
        private readonly Checksum $checksum,
    ) {}

    public function forceDeleted(Media $media): void
    {
        $this->deleteVariants($media);

        if ($this->checksum->isSharedByOthers($media)) {
            return;
        }

        $disk = Storage::disk($media->disk);
        $disk->delete($media->getPath());

        // Tidy the now-empty `{uuid}/` directory, but only when the original actually lived under
        // this row's own uuid — a deduped row's original is the canonical row's directory.
        $ownDirectory = rtrim($media->uuid, '/');

        if ($ownDirectory !== '' && str_starts_with($media->getPath(), $media->uuid.'/')) {
            $disk->deleteDirectory($ownDirectory);
        }
    }

    /** Remove this row's own variant files (and the variants directory) on whichever disk holds them. */
    private function deleteVariants(Media $media): void
    {
        $disk = Storage::disk($media->variants_disk ?? $media->disk);

        foreach (array_keys($media->generated_variants ?? []) as $name) {
            $disk->delete($media->getPath((string) $name));
        }

        $directory = rtrim($media->getPathForVariantsDirectory(), '/');

        if ($directory !== '') {
            $disk->deleteDirectory($directory);
        }
    }
}
