<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Commands\BackfillVariantSizesCommand;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\VariantSizeBackfill;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;
use Throwable;

/**
 * Records the size of every generated variant of one media whose record has none (written before
 * sizes were), measured from the disk the variant lives on. Records that already carry a size are
 * left alone, so a run can be repeated or resumed at any point.
 *
 * The disks are asked outside any lock; the sizes are then written under the row's lock, and only
 * to records still pointing at the file that was measured — a re-render meanwhile recorded its own
 * size. The write is a plain update: no model events, and no `updated_at` bump that would change a
 * CDN cache-bust.
 *
 * @internal building block of {@see BackfillVariantSizesCommand}
 */
final class BackfillVariantSizesAction
{
    public function execute(Media $media, bool $dryRun = false): VariantSizeBackfill
    {
        $measured = [];
        $unreadable = [];

        foreach ($media->generatedVariants() as $name => $variant) {
            if ($variant->size !== null) {
                continue;
            }

            $size = $this->measure($variant->disk, $media->getPath($name));

            if ($size === null) {
                $unreadable[] = $name;
            } else {
                $measured[$name] = $size;
            }
        }

        if ($measured !== [] && ! $dryRun) {
            $measured = $this->record($media, $measured);
        }

        return new VariantSizeBackfill(array_map('strval', array_keys($measured)), array_sum($measured), $unreadable);
    }

    private function measure(string $disk, string $path): ?int
    {
        try {
            return Storage::disk($disk)->size($path);
        } catch (Throwable) {
            // A missing file, an unreachable disk: the size stays unknown and a later run retries it.
            return null;
        }
    }

    /**
     * Write the measured sizes under the row's lock. Returns the ones written.
     *
     * @param  array<string, int>  $measured
     * @return array<string, int>
     */
    private function record(Media $media, array $measured): array
    {
        return $media->getConnection()->transaction(static function () use ($media, $measured): array {
            $locked = MediaModel::query()->withTrashed()->whereKey($media->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return [];
            }

            $written = [];

            foreach ($measured as $name => $size) {
                $current = $locked->generatedVariant($name);
                $read = $media->generatedVariant($name);

                if ($current === null || $read === null || $current->size !== null
                    || $current->disk !== $read->disk || $current->fileName !== $read->fileName) {
                    continue;
                }

                $locked->recordGeneratedVariant($name, $current->withSize($size));
                $written[$name] = $size;
            }

            if ($written === []) {
                return [];
            }

            $encoded = $locked->getAttributes()['generated_variants'];

            MediaModel::query()->withTrashed()->whereKey($locked->getKey())->toBase()->update(['generated_variants' => $encoded]);

            $media->setRawAttributes(array_merge($media->getAttributes(), ['generated_variants' => $encoded]));
            $media->syncOriginalAttribute('generated_variants');

            return $written;
        });
    }
}
