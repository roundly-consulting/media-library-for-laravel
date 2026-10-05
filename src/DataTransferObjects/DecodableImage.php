<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\DataTransferObjects;

use RoundlyConsulting\MediaLibrary\Support\ImageDecodeGuard;

/**
 * A local image {@see ImageDecodeGuard} cleared for decoding: its sniffed type, the ImageMagick
 * coder that must read it, and its size as declared by its header.
 *
 * @internal
 */
final readonly class DecodableImage
{
    public function __construct(
        public string $mimeType,
        public string $coder,
        public int $width,
        public int $height,
    ) {}
}
