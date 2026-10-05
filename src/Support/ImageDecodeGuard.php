<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

use Closure;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\DecodableImage;
use RoundlyConsulting\MediaLibrary\Exceptions\InvalidVariant;

/**
 * What the package agrees to decode, checked before any decoder sees the file.
 *
 *  - **Raster types only.** The type is sniffed from the bytes and must be one of the raster
 *    formats below; each maps to the ImageMagick coder that must read it, so a file never gets
 *    to pick its own decoder (an SVG — which can reference other files on the server — is never
 *    rendered, whatever it is called).
 *  - **A pixel cap.** The size comes from the header, without decoding, and an image larger than
 *    `media.max_image_pixels` is refused: a 48 KB PNG can declare 20000x20000.
 *
 * Refusals are {@see InvalidVariant}: an add skips the placeholder (and the variants) of such an
 * image and still stores it.
 *
 * @internal
 */
final class ImageDecodeGuard
{
    /** The raster types the package decodes, with the ImageMagick coder that reads each. */
    private const CODERS = [
        'image/jpeg' => 'JPEG',
        'image/png' => 'PNG',
        'image/gif' => 'GIF',
        'image/webp' => 'WEBP',
        'image/avif' => 'AVIF',
        'image/bmp' => 'BMP',
        'image/x-ms-bmp' => 'BMP',
        'image/tiff' => 'TIFF',
        'image/heic' => 'HEIC',
        'image/heif' => 'HEIC',
        'image/vnd.microsoft.icon' => 'ICO',
        'image/x-icon' => 'ICO',
    ];

    /** Whether media of this type is ever decoded for variants and placeholders. */
    public static function decodes(?string $mimeType): bool
    {
        return $mimeType !== null && isset(self::CODERS[$mimeType]);
    }

    /**
     * Clear a local image for decoding. `$measure` reads the size of a type PHP's header reader
     * does not know (HEIC), given the coder; it must not decode the pixels.
     *
     * @param  (Closure(string $coder): (array{0: int, 1: int}|null))|null  $measure
     *
     * @throws InvalidVariant when the type is not decodable, the size is unreadable or over the cap
     */
    public static function inspect(string $path, ?Closure $measure = null): DecodableImage
    {
        $mimeType = self::sniff($path);

        if (! self::decodes($mimeType)) {
            throw InvalidVariant::notDecodable($mimeType);
        }

        $coder = self::CODERS[(string) $mimeType];
        $size = @getimagesize($path);
        $dimensions = is_array($size) ? [$size[0], $size[1]] : ($measure === null ? null : $measure($coder));

        if ($dimensions === null || $dimensions[0] < 1 || $dimensions[1] < 1) {
            throw InvalidVariant::undecodable('its dimensions cannot be read');
        }

        [$width, $height] = $dimensions;
        $maxPixels = MediaConfig::maxImagePixels();

        if ($maxPixels !== null && $width * $height > $maxPixels) {
            throw InvalidVariant::tooManyPixels($width, $height, $maxPixels);
        }

        return new DecodableImage((string) $mimeType, $coder, $width, $height);
    }

    private static function sniff(string $path): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        $mimeType = @finfo_file($finfo, $path);
        finfo_close($finfo);

        return is_string($mimeType) ? $mimeType : null;
    }
}
