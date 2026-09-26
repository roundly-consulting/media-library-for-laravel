<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Contracts\ImageDriver;
use RoundlyConsulting\MediaLibrary\Support\ExifOrientation;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\OrientedJpeg;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\GdDriver;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\ImagickDriver;

/**
 * Phone cameras store pixels as captured and record an EXIF Orientation tag. Every driver must
 * turn the pixels upright on load — width/height, variants and placeholders all read the
 * loaded image — and must never leave a flag in its output that makes a viewer turn it again.
 */
function orientedDriver(string $name): ImageDriver
{
    return $name === 'imagick' ? new ImagickDriver : new GdDriver;
}

$orientationDrivers = array_values(array_filter([
    extension_loaded('gd') ? 'gd' : null,
    extension_loaded('imagick') ? 'imagick' : null,
]));

dataset('orientation drivers', $orientationDrivers);
dataset('orientations', range(1, 8));

beforeEach(function (): void {
    if (! extension_loaded('gd')) {
        $this->markTestSkipped('ext-gd builds the oriented fixtures.');
    }
});

it('turns every exif orientation upright on load', function (string $driver, int $orientation): void {
    $path = OrientedJpeg::path($orientation);

    try {
        $image = orientedDriver($driver)->load($path);

        expect([$image->width(), $image->height()])->toBe([OrientedJpeg::DISPLAY_WIDTH, OrientedJpeg::DISPLAY_HEIGHT])
            ->and(OrientedJpeg::quadrantsOf($image->format('png')->encode()))->toBe(OrientedJpeg::QUADRANTS);
    } finally {
        @unlink($path);
    }
})->with('orientation drivers')->with('orientations');

it('reads a little-endian (intel) exif block the same way', function (string $driver, int $orientation): void {
    $path = OrientedJpeg::path($orientation, littleEndian: true);

    try {
        $image = orientedDriver($driver)->load($path);

        expect(OrientedJpeg::quadrantsOf($image->format('png')->encode()))->toBe(OrientedJpeg::QUADRANTS);
    } finally {
        @unlink($path);
    }
})->with('orientation drivers')->with([6, 8]);

it('leaves no orientation flag in a jpeg it writes', function (string $driver, int $orientation): void {
    $path = OrientedJpeg::path($orientation);
    $out = sys_get_temp_dir().'/oriented-out-'.uniqid().'.jpg';

    try {
        orientedDriver($driver)->load($path)->fit('contain', 32, 32)->format('jpg')->save($out);

        // A leftover 6 would make every browser rotate the already-upright pixels a second time.
        $size = getimagesize($out);

        expect(ExifOrientation::fromFile($out))->toBe(1)
            ->and([$size[0] ?? 0, $size[1] ?? 0])->toBe([32, 16]);
    } finally {
        @unlink($path);
        @unlink($out);
    }
})->with('orientation drivers')->with([3, 6, 8]);

it('samples placeholder pixels from the upright image', function (string $driver): void {
    $path = OrientedJpeg::path(6);

    try {
        $pixels = orientedDriver($driver)->load($path)->rgbaPixels(16);

        expect([$pixels->width, $pixels->height])->toBe([16, 8]);
    } finally {
        @unlink($path);
    }
})->with('orientation drivers');

it('reads the orientation tag of every value in both byte orders', function (int $orientation): void {
    expect(ExifOrientation::fromBytes(OrientedJpeg::bytes($orientation)))->toBe($orientation)
        ->and(ExifOrientation::fromBytes(OrientedJpeg::bytes($orientation, littleEndian: true)))->toBe($orientation);
})->with('orientations');

it('reports whether an orientation swaps width and height', function (): void {
    expect(array_map(ExifOrientation::swapsDimensions(...), range(1, 8)))
        ->toBe([false, false, false, false, true, true, true, true]);
});

/**
 * A JPEG header whose IFD0 holds exactly the given `[tag, type, value]` entries (big-endian).
 *
 * @param  list<array{0: int, 1: int, 2: int}>  $entries
 */
function jpegWithIfd(array $entries): string
{
    $tiff = 'MM'.pack('n', 42).pack('N', 8).pack('n', count($entries));

    foreach ($entries as [$tag, $type, $value]) {
        $tiff .= pack('n', $tag).pack('n', $type).pack('N', 1).pack('n', $value)."\0\0";
    }

    $payload = "Exif\0\0".$tiff.pack('N', 0);

    return "\xFF\xD8\xFF\xE1".pack('n', strlen($payload) + 2).$payload."\xFF\xDA";
}

it('finds the orientation after the other ifd0 tags a camera writes first', function (): void {
    // Real cameras list Make (0x010F) and Model (0x0110) ahead of Orientation (0x0112).
    expect(ExifOrientation::fromBytes(jpegWithIfd([[0x010F, 2, 0], [0x0110, 2, 0], [0x0112, 3, 6]])))->toBe(6);
});

it('treats anything without a usable orientation tag as upright', function (string $bytes): void {
    expect(ExifOrientation::fromBytes($bytes))->toBe(1);
})->with([
    'empty' => '',
    'not an image' => 'plain text',
    'png' => fn (): string => (string) file_get_contents(__DIR__.'/../files/wide.png'),
    'jpeg without exif' => fn (): string => (string) file_get_contents(__DIR__.'/../files/landscape.jpg'),
    'truncated app1' => "\xFF\xD8\xFF\xE1\x00\x40Exif\0\0MM",
    'bad byte order' => "\xFF\xD8\xFF\xE1\x00\x1CExif\0\0XX\x00\x2A\x00\x00\x00\x08\x00\x00\x00\x00\x00\x00",
    'out of range value' => fn (): string => str_replace("\x01\x12\x00\x03\x00\x00\x00\x01\x00\x06", "\x01\x12\x00\x03\x00\x00\x00\x01\x00\x09", OrientedJpeg::bytes(6)),
    'image data before exif' => "\xFF\xD8\xFF\xDA\x00\x02",
    'segment length below its own size' => "\xFF\xD8\xFF\xE0\x00\x01\x00\x00",
    'ifd0 without an orientation tag' => fn (): string => jpegWithIfd([[0x010F, 2, 0]]),
    'orientation stored as the wrong type' => fn (): string => jpegWithIfd([[0x0112, 4, 6]]),
]);

it('reads the orientation from a file path and tolerates a missing file', function (): void {
    $path = OrientedJpeg::path(8);

    try {
        expect(ExifOrientation::fromFile($path))->toBe(8)
            ->and(ExifOrientation::fromFile($path.'.missing'))->toBe(1);
    } finally {
        @unlink($path);
    }
});
