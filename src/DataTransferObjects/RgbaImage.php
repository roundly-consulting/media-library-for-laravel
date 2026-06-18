<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\DataTransferObjects;

use RoundlyConsulting\MediaLibrary\Contracts\ImageDriver;

/**
 * A small, decoded RGBA raster used by the placeholder encoders (ThumbHash / Blurhash).
 *
 * Pixels are a flat, row-major list of 0..255 bytes, four per pixel (R, G, B, A), so
 * `pixels[(y * width + x) * 4 + channel]`. Produced by an {@see ImageDriver}
 * from a downscaled copy of the original to bound encoding cost.
 */
final readonly class RgbaImage
{
    /**
     * @param  list<int>  $pixels
     */
    public function __construct(
        public int $width,
        public int $height,
        public array $pixels,
    ) {}
}
