<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Tests\Fixtures;

use RuntimeException;

/**
 * Builds tiny JPEGs the way a phone camera does: the sensor's pixels are stored as captured and
 * an EXIF `Orientation` tag (1..8) says how to turn them upright for display.
 *
 * Every fixture DISPLAYS as the same 64x32 image — four solid quadrants, red / green on top,
 * blue / yellow below — so a correctly auto-oriented load of any orientation must yield exactly
 * that picture. The stored pixels are derived by plain coordinate maps straight from the EXIF
 * spec, NOT through GD/Imagick rotate calls, so the fixtures can't share a bug with the drivers
 * they test.
 */
final class OrientedJpeg
{
    public const DISPLAY_WIDTH = 64;

    public const DISPLAY_HEIGHT = 32;

    /** Display quadrants → the colour a correctly oriented image shows there. */
    public const QUADRANTS = [
        'top-left' => 'red',
        'top-right' => 'green',
        'bottom-left' => 'blue',
        'bottom-right' => 'yellow',
    ];

    private const COLOURS = [
        'red' => [255, 0, 0],
        'green' => [0, 255, 0],
        'blue' => [0, 0, 255],
        'yellow' => [255, 255, 0],
    ];

    /** Write the fixture for `$orientation` to a temp file and return its path. */
    public static function path(int $orientation, bool $littleEndian = false): string
    {
        $path = sys_get_temp_dir().'/oriented-'.$orientation.'-'.uniqid().'.jpg';

        file_put_contents($path, self::bytes($orientation, $littleEndian));

        return $path;
    }

    /** JPEG bytes whose stored pixels, turned per `$orientation`, display as the canonical image. */
    public static function bytes(int $orientation, bool $littleEndian = false): string
    {
        $transposed = $orientation >= 5;
        $storedWidth = $transposed ? self::DISPLAY_HEIGHT : self::DISPLAY_WIDTH;
        $storedHeight = $transposed ? self::DISPLAY_WIDTH : self::DISPLAY_HEIGHT;

        $image = imagecreatetruecolor($storedWidth, $storedHeight);

        if ($image === false) {
            throw new RuntimeException('GD could not allocate the fixture canvas.');
        }

        $palette = array_map(
            fn (array $rgb): int => (int) imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]),
            self::COLOURS,
        );

        for ($sy = 0; $sy < $storedHeight; $sy++) {
            for ($sx = 0; $sx < $storedWidth; $sx++) {
                [$dx, $dy] = self::displayCoordinates($orientation, $sx, $sy, $storedWidth, $storedHeight);

                imagesetpixel($image, $sx, $sy, $palette[self::colourAt($dx, $dy)]);
            }
        }

        ob_start();
        imagejpeg($image, null, 100);
        $jpeg = (string) ob_get_clean();

        // Splice the EXIF APP1 segment in right after SOI (FFD8), where cameras put it.
        return substr($jpeg, 0, 2).self::exifSegment($orientation, $littleEndian).substr($jpeg, 2);
    }

    /**
     * Name the colour at the centre of each display quadrant of an encoded image.
     *
     * @return array<string, string>
     */
    public static function quadrantsOf(string $bytes): array
    {
        $image = imagecreatefromstring($bytes);

        if ($image === false) {
            throw new RuntimeException('Could not decode the image under test.');
        }

        // Imagick writes few-colour PNGs as palettes, where imagecolorat() returns an index.
        imagepalettetotruecolor($image);

        $width = imagesx($image);
        $height = imagesy($image);

        $points = [
            'top-left' => [$width / 4, $height / 4],
            'top-right' => [$width * 3 / 4, $height / 4],
            'bottom-left' => [$width / 4, $height * 3 / 4],
            'bottom-right' => [$width * 3 / 4, $height * 3 / 4],
        ];

        $named = [];

        foreach ($points as $quadrant => [$x, $y]) {
            $rgb = imagecolorat($image, (int) $x, (int) $y);
            $named[$quadrant] = self::nearestColour(($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);
        }

        return $named;
    }

    /**
     * Where stored pixel (sx, sy) lands on the display — the EXIF 2.3 definition of each tag,
     * e.g. 6 = "row 0 is the visual right-hand side, column 0 is the visual top".
     *
     * @return array{0: int, 1: int}
     */
    private static function displayCoordinates(int $orientation, int $sx, int $sy, int $width, int $height): array
    {
        return match ($orientation) {
            2 => [$width - 1 - $sx, $sy],
            3 => [$width - 1 - $sx, $height - 1 - $sy],
            4 => [$sx, $height - 1 - $sy],
            5 => [$sy, $sx],
            6 => [$height - 1 - $sy, $sx],
            7 => [$height - 1 - $sy, $width - 1 - $sx],
            8 => [$sy, $width - 1 - $sx],
            default => [$sx, $sy],
        };
    }

    private static function colourAt(int $dx, int $dy): string
    {
        $right = $dx >= self::DISPLAY_WIDTH / 2;
        $bottom = $dy >= self::DISPLAY_HEIGHT / 2;

        return self::QUADRANTS[($bottom ? 'bottom' : 'top').'-'.($right ? 'right' : 'left')];
    }

    private static function nearestColour(int $r, int $g, int $b): string
    {
        $best = 'red';
        $bestDistance = PHP_INT_MAX;

        foreach (self::COLOURS as $name => [$cr, $cg, $cb]) {
            $distance = ($r - $cr) ** 2 + ($g - $cg) ** 2 + ($b - $cb) ** 2;

            if ($distance < $bestDistance) {
                $best = $name;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /** A minimal APP1 "Exif" segment holding one IFD0 entry: Orientation (0x0112, SHORT). */
    private static function exifSegment(int $orientation, bool $littleEndian): string
    {
        $short = $littleEndian ? 'v' : 'n';
        $long = $littleEndian ? 'V' : 'N';

        $tiff = ($littleEndian ? 'II' : 'MM')
            .pack($short, 42)
            .pack($long, 8)
            .pack($short, 1)
            .pack($short, 0x0112).pack($short, 3).pack($long, 1).pack($short, $orientation)."\0\0"
            .pack($long, 0);

        $payload = "Exif\0\0".$tiff;

        return "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
    }
}
