<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Contracts;

use RoundlyConsulting\MediaLibrary\DataTransferObjects\RgbaImage;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\GdDriver;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\ImagickDriver;

/**
 * A thin, fluent image-manipulation seam over the available PHP image extension.
 *
 * Implementations ({@see ImagickDriver},
 * {@see GdDriver}) wrap exactly the subset
 * of operations the variant engine needs — load, measure, resize/fit, reformat, and save — so
 * no third-party image library is required (Laravel/Symfony + ext-* only).
 */
interface ImageDriver
{
    /** Load an image from a path on the local filesystem. */
    public function load(string $path): self;

    public function width(): int;

    public function height(): int;

    /**
     * Resize within the given mode.
     *
     * @param  'contain'|'cover'|'crop'|'fill'|'stretch'  $mode
     */
    public function fit(string $mode, ?int $width, ?int $height): self;

    /** Scale to an explicit size keeping aspect ratio when one dimension is null. */
    public function resize(?int $width, ?int $height): self;

    /** Target output format (driver-supported subset of jpg/jpeg/png/webp/avif/gif). */
    public function format(string $format): self;

    /** Output quality (1..100) for lossy formats. */
    public function quality(int $quality): self;

    /** Background/flatten color (hex) used when padding or flattening transparency. */
    public function background(string $color): self;

    /** Optional unsharp-mask style sharpening; a no-op where the driver can't sharpen. */
    public function sharpen(int $amount): self;

    /** Encode the manipulated image to bytes in the configured output format. */
    public function encode(): string;

    /** Write the manipulated image to a path on the local filesystem. */
    public function save(string $path): void;

    /**
     * Read the loaded image as RGBA pixels, downscaled so neither side exceeds `$maxSize`
     * (never upscaled). Used to compute LQIP placeholders on a cheap, bounded raster.
     */
    public function rgbaPixels(int $maxSize): RgbaImage;

    /** Whether this driver/build can produce the given output format. */
    public function supportsFormat(string $format): bool;

    /** Driver identifier (`imagick` | `gd`) for error messages. */
    public function name(): string;
}
