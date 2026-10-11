<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Observers;

use Illuminate\Support\Facades\DB;
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
 *
 * All of it runs once the deleting transaction commits — at once when none is open. A rollback
 * restores the row, so its files must still be there; and a row another transaction made share
 * this original meanwhile is only visible to the reference count after that commit.
 */
final class MediaObserver
{
    public function __construct(
        private readonly StoredFiles $files,
    ) {}

    public function forceDeleted(Media $media): void
    {
        DB::afterCommit(function () use ($media): void {
            $this->files->deleteVariants($media);
            $this->files->deleteOriginalUnlessShared($media);
            $this->files->tidyDirectories($media);
        });
    }
}
