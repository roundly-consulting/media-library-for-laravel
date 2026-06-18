<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Observers;

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Contracts\PathGenerator;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * Cleans up stored files when a media row is permanently removed.
 *
 * Soft deletes intentionally keep the files on disk so a restore is lossless; only a
 * `forceDelete()` removes the original (and, in later phases, its variant directory).
 */
final class MediaObserver
{
    public function __construct(
        private readonly PathGenerator $pathGenerator,
    ) {}

    public function forceDeleted(Media $media): void
    {
        $disk = Storage::disk($media->disk);
        $disk->delete($media->getPath());

        // Remove the media's whole directory (original + variants stored on the same disk).
        $directory = rtrim($this->pathGenerator->getPath($media), '/');

        if ($directory !== '') {
            $disk->deleteDirectory($directory);
        }

        // Variants stored on a separate disk live under the same uuid path there too.
        if ($media->variants_disk !== null && $media->variants_disk !== $media->disk) {
            $variantsDirectory = rtrim($this->pathGenerator->getPath($media), '/');

            if ($variantsDirectory !== '') {
                Storage::disk($media->variants_disk)->deleteDirectory($variantsDirectory);
            }
        }
    }
}
