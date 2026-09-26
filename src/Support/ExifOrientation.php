<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

/**
 * Reads the EXIF `Orientation` tag (0x0112) of a JPEG — the flag a phone camera sets instead of
 * rotating the sensor's pixels.
 *
 * A self-contained reader over the JPEG APP1 segment, so orientation is honoured on every
 * install: ext-exif is not required (it is optional and often missing on slim PHP builds), and
 * the GD driver and the recorded width/height read the tag the same way. Anything unreadable —
 * not a JPEG, no EXIF block, a truncated or malformed one, an out-of-range value — is reported
 * as {@see UPRIGHT}, i.e. "use the pixels as stored".
 *
 * Values follow EXIF 2.3: 1 upright, 2 mirrored, 3 rotated 180°, 4 flipped, 5 transposed,
 * 6 needs 90° clockwise, 7 transversed, 8 needs 90° counter-clockwise.
 */
final class ExifOrientation
{
    public const UPRIGHT = 1;

    /** EXIF lives in the header segments; this bounds how much of a large file is read. */
    private const HEADER_BYTES = 262144;

    private const ORIENTATION_TAG = 0x0112;

    private const TYPE_SHORT = 3;

    public static function fromFile(string $path): int
    {
        $handle = is_file($path) ? @fopen($path, 'rb') : false;

        if ($handle === false) {
            return self::UPRIGHT;
        }

        $head = (string) fread($handle, self::HEADER_BYTES);
        fclose($handle);

        return self::fromBytes($head);
    }

    public static function fromBytes(string $bytes): int
    {
        if (! str_starts_with($bytes, "\xFF\xD8")) {
            return self::UPRIGHT;
        }

        $offset = 2;
        $length = strlen($bytes);

        while ($offset + 4 <= $length && $bytes[$offset] === "\xFF") {
            $marker = ord($bytes[$offset + 1]);

            // Start of scan / end of image: past every metadata segment.
            if ($marker === 0xDA || $marker === 0xD9) {
                break;
            }

            $segmentLength = self::unsigned('n', $bytes, $offset + 2, 2);

            if ($segmentLength === null || $segmentLength < 2) {
                break;
            }

            if ($marker === 0xE1 && substr($bytes, $offset + 4, 6) === "Exif\0\0") {
                return self::fromTiff(substr($bytes, $offset + 10, $segmentLength - 8));
            }

            $offset += 2 + $segmentLength;
        }

        return self::UPRIGHT;
    }

    /** Orientations 5–8 turn the image a quarter, so the displayed width is the stored height. */
    public static function swapsDimensions(int $orientation): bool
    {
        return $orientation >= 5 && $orientation <= 8;
    }

    /**
     * The width/height a viewer sees: the stored pixel size, swapped when the EXIF tag turns the
     * image a quarter. Null when the file is not a readable image.
     *
     * @return array{0: int, 1: int}|null
     */
    public static function displayDimensions(string $path): ?array
    {
        $size = @getimagesize($path);

        if (! is_array($size)) {
            return null;
        }

        [$width, $height] = [$size[0], $size[1]];

        return self::swapsDimensions(self::fromFile($path)) ? [$height, $width] : [$width, $height];
    }

    /** Walk IFD0 of the TIFF structure inside the APP1 payload for the orientation entry. */
    private static function fromTiff(string $tiff): int
    {
        [$short, $long] = match (substr($tiff, 0, 2)) {
            'MM' => ['n', 'N'],
            'II' => ['v', 'V'],
            default => [null, null],
        };

        if ($short === null || $long === null) {
            return self::UPRIGHT;
        }

        $ifd = self::unsigned($long, $tiff, 4, 4);
        $entries = $ifd === null ? null : self::unsigned($short, $tiff, $ifd, 2);

        if ($ifd === null || $entries === null) {
            return self::UPRIGHT;
        }

        for ($i = 0; $i < $entries; $i++) {
            $entry = $ifd + 2 + $i * 12;

            if (self::unsigned($short, $tiff, $entry, 2) !== self::ORIENTATION_TAG) {
                continue;
            }

            $value = self::unsigned($short, $tiff, $entry + 8, 2);

            return self::unsigned($short, $tiff, $entry + 2, 2) === self::TYPE_SHORT
                && $value !== null && $value >= 1 && $value <= 8
                ? $value
                : self::UPRIGHT;
        }

        return self::UPRIGHT;
    }

    private static function unsigned(string $format, string $bytes, int $offset, int $size): ?int
    {
        if ($offset < 0 || $offset + $size > strlen($bytes)) {
            return null;
        }

        $value = unpack($format, $bytes, $offset);

        return is_array($value) && is_int($value[1] ?? null) ? $value[1] : null;
    }
}
