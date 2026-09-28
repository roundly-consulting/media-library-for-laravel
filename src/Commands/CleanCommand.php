<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Observers\MediaObserver;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;
use RoundlyConsulting\MediaLibrary\Support\StoredFiles;

/**
 * Conservatively reclaims storage:
 *   - removes orphaned variant files — files in a media's own variants directory that its
 *     `generated_variants` does not record on that disk;
 *   - never removes a file any row (soft-deleted ones included) stores as its original, so a
 *     layout that keeps variants next to the original is safe;
 *   - never touches soft-deleted media, and never scans a directory the layout shares between
 *     media (one without the media's uuid as a path segment).
 *
 * Files for force-deleted/permanently-gone rows are already removed by the
 * {@see MediaObserver} on `forceDelete()`, so a
 * permanently-gone row leaves nothing for this command to clean.
 */
final class CleanCommand extends Command
{
    protected $signature = 'media:clean';

    protected $description = 'Remove orphaned variant files, leaving soft-deleted media untouched';

    public function handle(StoredFiles $files): int
    {
        $removed = 0;

        MediaModel::query()->lazyById()->each(function (Media $media) use ($files, &$removed): void {
            $removed += $this->cleanVariants($media, $files);
        });

        $this->info("Removed {$removed} orphaned variant file(s).");

        return self::SUCCESS;
    }

    private function cleanVariants(Media $media, StoredFiles $files): int
    {
        $directory = rtrim($media->getPathForVariantsDirectory(), '/');

        if ($directory === '' || ! $files->isOwnDirectory($media, $directory)) {
            return 0;
        }

        $removed = 0;

        foreach ($this->variantDisks($media) as $disk) {
            $storage = Storage::disk($disk);

            if (! $storage->exists($directory)) {
                continue;
            }

            $candidates = [];

            foreach ($storage->files($directory) as $file) {
                if (! in_array($file, $this->expectedVariantPaths($media, $disk), true)) {
                    $candidates[] = $file;
                }
            }

            $originals = $files->referencedOriginals($disk, $candidates);

            foreach (array_diff($candidates, $originals) as $file) {
                $storage->delete($file);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Every disk this media's variants may live on.
     *
     * @return list<string>
     */
    private function variantDisks(Media $media): array
    {
        $disks = [$media->variants_disk ?? $media->disk];

        foreach ($media->generatedVariants() as $variant) {
            $disks[] = $variant->disk;
        }

        return array_values(array_unique($disks));
    }

    /**
     * The variant files this media records on `$disk`.
     *
     * @return list<string>
     */
    private function expectedVariantPaths(Media $media, string $disk): array
    {
        $paths = [];

        foreach ($media->generatedVariants() as $name => $variant) {
            if ($variant->disk === $disk) {
                $paths[] = $media->getPath($name);
            }
        }

        return $paths;
    }
}
