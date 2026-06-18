<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Relocates or duplicates a single stored file between disks.
 *
 * Same-disk transfers use the filesystem's native `move()`/`copy()`; cross-disk transfers stream
 * `readStream()` → `writeStream()` so large files never need to be buffered in memory.
 *
 * Phase 5 will wrap the {@see self::delete()} seam with the `(disk, visibility, checksum)` refcount
 * guard so shared (deduplicated) files are only removed when the last referrer goes; for now each
 * media owns its files, so deletes are unconditional.
 */
final class FileTransfer
{
    /**
     * Copy a file from one disk/path to another. Returns false when the source is missing.
     */
    public function copy(string $fromDisk, string $fromPath, string $toDisk, string $toPath, string $visibility): bool
    {
        $from = Storage::disk($fromDisk);

        if (! $from->exists($fromPath)) {
            return false;
        }

        if ($fromDisk === $toDisk) {
            if ($fromPath === $toPath) {
                return true;
            }

            return $from->copy($fromPath, $toPath);
        }

        $stream = $from->readStream($fromPath);

        if (! is_resource($stream)) {
            return false;
        }

        $written = Storage::disk($toDisk)->writeStream($toPath, $stream, ['visibility' => $visibility]);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return $written;
    }

    /**
     * Move a file from one disk/path to another, deleting the source. Returns false when the
     * source is missing.
     */
    public function move(string $fromDisk, string $fromPath, string $toDisk, string $toPath, string $visibility): bool
    {
        if ($fromDisk === $toDisk) {
            if ($fromPath === $toPath) {
                return true;
            }

            return Storage::disk($fromDisk)->move($fromPath, $toPath);
        }

        if (! $this->copy($fromDisk, $fromPath, $toDisk, $toPath, $visibility)) {
            return false;
        }

        $this->delete($fromDisk, $fromPath);

        return true;
    }

    /**
     * Remove a stored file. This is the seam Phase 5 wraps with the refcount guard.
     */
    public function delete(string $disk, string $path): void
    {
        Storage::disk($disk)->delete($path);
    }
}
