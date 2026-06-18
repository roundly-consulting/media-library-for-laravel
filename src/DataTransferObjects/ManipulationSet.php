<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\DataTransferObjects;

use RoundlyConsulting\MediaLibrary\Variants\Variant;

/**
 * A fully-resolved set of manipulations for a single variant, with all defaults applied.
 *
 * Produced by {@see Variant::resolve()} and consumed by
 * the variant engine, so no "shape" arrays cross the boundary.
 */
final readonly class ManipulationSet
{
    /**
     * @param  'contain'|'cover'|'crop'|'fill'|'stretch'  $fit
     */
    public function __construct(
        public string $name,
        public ?int $width,
        public ?int $height,
        public string $fit,
        public string $format,
        public int $quality,
        public string $background,
        public int $sharpen,
    ) {}
}
