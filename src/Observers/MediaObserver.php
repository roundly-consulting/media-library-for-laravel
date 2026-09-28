<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Observers;

use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\StoredFiles;

/**
 * Cleans up stored files when a media row is permanently removed.
 *
 * Soft deletes intentionally keep the files on disk so a restore is lossless; only a
 * `forceDelete()` removes them.
 *
 * The original is **refcount-guarded**: while any other row — soft-deleted ones included — still
 * points at the same stored file, it is left in place and only this row's own variants go.
 * Variants are always per-row and are deleted from the disk each one was written to. Directories
 * are tidied last, and never while they still hold a file another row points at.
 */
final class MediaObserver
{
    public function __construct(
        private readonly StoredFiles $files,
    ) {}

    public function forceDeleted(Media $media): void
    {
        $this->files->deleteVariants($media);
        $this->files->deleteOriginalUnlessShared($media);
        $this->files->tidyDirectories($media);
    }
}
