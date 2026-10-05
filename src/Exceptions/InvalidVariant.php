<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

use Throwable;

final class InvalidVariant extends MediaLibraryException
{
    public static function unknownName(string $name): self
    {
        return new self("There is no variant named [{$name}] registered for this media.");
    }

    public static function unsupportedFormat(string $format, string $driver): self
    {
        return new self("The [{$driver}] image driver cannot produce the [{$format}] format.");
    }

    public static function invalidFitMode(string $mode): self
    {
        return new self("[{$mode}] is not a valid variant fit mode.");
    }

    public static function invalidQuality(int $quality): self
    {
        return new self("Variant quality must be between 1 and 100, [{$quality}] given.");
    }

    /** The type is not a raster image the package decodes (SVG, PDF, …), whatever its name says. */
    public static function notDecodable(?string $mimeType): self
    {
        $type = $mimeType ?? 'unknown';

        return new self("Images of type [{$type}] are never decoded; only raster images get variants and placeholders.");
    }

    public static function tooManyPixels(int $width, int $height, int $maxPixels): self
    {
        return new self(
            "The image is {$width}x{$height}, more than the {$maxPixels} pixels the package decodes (media.max_image_pixels)."
        );
    }

    public static function undecodable(string $reason, ?Throwable $previous = null): self
    {
        return new self("The image could not be decoded: {$reason}.", 0, $previous);
    }

    public static function canvasUnavailable(int $width, int $height): self
    {
        return new self("The image driver could not allocate a {$width}x{$height} canvas.");
    }

    public static function notGenerated(string $name): self
    {
        return new self("The variant [{$name}] has not been generated for this media.");
    }
}
