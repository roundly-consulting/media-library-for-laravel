<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\ImageDecodeGuard;
use RoundlyConsulting\MediaLibrary\Variants\Variant;

/**
 * Re-renders a {@see Media}'s variants from its owning model's current bucket definitions.
 *
 * By default only variants that have not been generated yet are rendered; `$force` re-renders
 * those too. `$only` narrows the run to the named variants (empty = every applicable variant).
 * Names that match no definition are ignored. Returns the names that were rendered, in
 * definition order — empty when there was nothing to do.
 */
final class RegenerateVariantsAction
{
    public function __construct(
        private readonly GenerateVariantsAction $generateVariants,
    ) {}

    /**
     * @param  list<string>  $only
     * @return list<string>
     */
    public function execute(Media $media, array $only = [], bool $force = false): array
    {
        $variants = $this->select($media, $only, $force);

        if ($variants === []) {
            return [];
        }

        $this->generateVariants->execute($media, $variants);

        return array_map(static fn (Variant $variant): string => $variant->name, $variants);
    }

    /**
     * @param  list<string>  $only
     * @return list<Variant>
     */
    private function select(Media $media, array $only, bool $force): array
    {
        // Only raster images get variants: an SVG is never decoded, so it never gets any.
        if (! ImageDecodeGuard::decodes($media->mime_type)) {
            return [];
        }

        $selected = [];

        foreach ($media->resolveVariants() as $variant) {
            if ($only !== [] && ! in_array($variant->name, $only, true)) {
                continue;
            }

            if (! $force && $media->hasGeneratedVariant($variant->name)) {
                continue;
            }

            $selected[] = $variant;
        }

        return $selected;
    }
}
