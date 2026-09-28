<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * Brings a media's generated variants in line with the bucket it now sits in: variants that bucket
 * does not define are deleted (file and record), and the ones it defines but the media lacks are
 * generated. Used whenever media changes owner or bucket — a bound draft, a move, a copy.
 *
 * A variant the new bucket also defines is kept as it is (re-render it with
 * `regenerate(force: true)` when the definitions differ).
 *
 * @internal building block
 */
final class ReconcileVariantsAction
{
    public function __construct(
        private readonly DispatchVariantsAction $dispatchVariants,
    ) {}

    public function execute(Media $media): void
    {
        $definitions = $media->isImage() ? $media->resolveVariants() : [];
        $defined = array_map(static fn ($variant): string => $variant->name, $definitions);

        $records = $media->generatedVariants();
        $stale = array_values(array_diff(array_map('strval', array_keys($media->generated_variants ?? [])), $defined));

        foreach ($stale as $name) {
            if (isset($records[$name])) {
                Storage::disk($records[$name]->disk)->delete($media->getPath($name));
            }

            $media->forgetGeneratedVariant($name);
        }

        if ($stale !== []) {
            $media->save();
        }

        $missing = array_values(array_filter(
            $definitions,
            static fn ($variant): bool => ! $media->hasGeneratedVariant($variant->name),
        ));

        $this->dispatchVariants->execute($media, $missing);
    }
}
