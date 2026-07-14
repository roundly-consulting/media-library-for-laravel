<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Observers\MediaObserver;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;

/**
 * Conservatively reclaims storage:
 *   - removes orphaned variant files (files in a media's variants directory that are not the
 *     original and are not listed in its `generated_variants`);
 *   - never touches soft-deleted media — a restore must stay lossless.
 *
 * Files for force-deleted/permanently-gone rows are already removed by the
 * {@see MediaObserver} on `forceDelete()`, so a
 * permanently-gone row leaves nothing for this command to clean.
 */
final class CleanCommand extends Command
{
    protected $signature = 'media:clean';

    protected $description = 'Remove orphaned variant files, leaving soft-deleted media untouched';

    public function handle(): int
    {
        $removed = 0;

        MediaModel::query()->each(function (Media $media) use (&$removed): void {
            $removed += $this->cleanVariants($media);
        });

        $this->info("Removed {$removed} orphaned variant file(s).");

        return self::SUCCESS;
    }

    private function cleanVariants(Media $media): int
    {
        $disk = Storage::disk($media->variants_disk ?? $media->disk);
        $directory = rtrim($media->getPathForVariantsDirectory(), '/');

        if ($directory === '' || ! $disk->exists($directory)) {
            return 0;
        }

        $keep = $this->expectedVariantFiles($media);

        $removed = 0;

        foreach ($disk->files($directory) as $file) {
            if (! in_array(basename($file), $keep, true)) {
                $disk->delete($file);
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * The variant filenames that should exist for this media (the ones it still references).
     *
     * @return list<string>
     */
    private function expectedVariantFiles(Media $media): array
    {
        $files = [];

        foreach (array_keys($media->generated_variants ?? []) as $name) {
            $files[] = basename($media->getPath((string) $name));
        }

        return $files;
    }
}
