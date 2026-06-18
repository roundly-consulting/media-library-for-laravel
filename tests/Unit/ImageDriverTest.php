<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Contracts\ImageDriver;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\GdDriver;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\ImagickDriver;

/**
 * @return array{0: int, 1: int}
 */
function dimensionsOf(string $bytes): array
{
    $info = getimagesizefromstring($bytes);

    return [$info[0] ?? 0, $info[1] ?? 0];
}

function driverFor(string $name): ImageDriver
{
    return $name === 'imagick' ? new ImagickDriver : new GdDriver;
}

$drivers = array_values(array_filter([
    extension_loaded('gd') ? 'gd' : null,
    extension_loaded('imagick') ? 'imagick' : null,
]));

dataset('drivers', $drivers);

it('reports the original dimensions', function (string $driver): void {
    $image = driverFor($driver)->load(__DIR__.'/../files/wide.png');

    expect($image->width())->toBe(40)
        ->and($image->height())->toBe(20);
})->with('drivers');

it('contains within a box keeping ratio', function (string $driver): void {
    $bytes = driverFor($driver)
        ->load(__DIR__.'/../files/wide.png')
        ->fit('contain', 20, 20)
        ->format('png')
        ->encode();

    [$w, $h] = dimensionsOf($bytes);

    // 40x20 contained in 20x20 → 20x10.
    expect($w)->toBe(20)->and($h)->toBe(10);
})->with('drivers');

it('crops to fill the exact box', function (string $driver): void {
    $bytes = driverFor($driver)
        ->load(__DIR__.'/../files/wide.png')
        ->fit('crop', 16, 16)
        ->format('png')
        ->encode();

    [$w, $h] = dimensionsOf($bytes);

    expect($w)->toBe(16)->and($h)->toBe(16);
})->with('drivers');

it('covers to the exact box', function (string $driver): void {
    $bytes = driverFor($driver)
        ->load(__DIR__.'/../files/wide.png')
        ->fit('cover', 10, 10)
        ->format('png')
        ->encode();

    [$w, $h] = dimensionsOf($bytes);

    expect($w)->toBe(10)->and($h)->toBe(10);
})->with('drivers');

it('fills and pads to the exact box', function (string $driver): void {
    $bytes = driverFor($driver)
        ->load(__DIR__.'/../files/wide.png')
        ->fit('fill', 30, 30)
        ->background('#ffffff')
        ->format('png')
        ->encode();

    [$w, $h] = dimensionsOf($bytes);

    expect($w)->toBe(30)->and($h)->toBe(30);
})->with('drivers');

it('stretches to the exact box ignoring ratio', function (string $driver): void {
    $bytes = driverFor($driver)
        ->load(__DIR__.'/../files/wide.png')
        ->fit('stretch', 25, 35)
        ->format('png')
        ->encode();

    [$w, $h] = dimensionsOf($bytes);

    expect($w)->toBe(25)->and($h)->toBe(35);
})->with('drivers');

it('scales by width keeping ratio when only width is given', function (string $driver): void {
    $bytes = driverFor($driver)
        ->load(__DIR__.'/../files/wide.png')
        ->fit('contain', 20, null)
        ->format('png')
        ->encode();

    [$w, $h] = dimensionsOf($bytes);

    expect($w)->toBe(20)->and($h)->toBe(10);
})->with('drivers');

it('encodes to the requested format', function (string $driver): void {
    $bytes = driverFor($driver)
        ->load(__DIR__.'/../files/wide.png')
        ->fit('contain', 20, 20)
        ->format('webp')
        ->quality(70)
        ->encode();

    $info = getimagesizefromstring($bytes);

    expect($info['mime'] ?? null)->toBe('image/webp');
})->with('drivers');

it('flattens transparency onto the background for jpg', function (string $driver): void {
    $bytes = driverFor($driver)
        ->load(__DIR__.'/../files/transparent.png')
        ->fit('contain', 20, 20)
        ->background('#ffffff')
        ->format('jpg')
        ->quality(80)
        ->encode();

    $info = getimagesizefromstring($bytes);

    expect($info['mime'] ?? null)->toBe('image/jpeg');
})->with('drivers');

it('writes a variant to a path', function (string $driver): void {
    $target = sys_get_temp_dir().'/variant-'.uniqid().'.png';

    driverFor($driver)
        ->load(__DIR__.'/../files/wide.png')
        ->fit('contain', 10, 10)
        ->format('png')
        ->save($target);

    expect(is_file($target))->toBeTrue();

    @unlink($target);
})->with('drivers');

it('honours quality for lossy formats', function (string $driver): void {
    $low = driverFor($driver)
        ->load(__DIR__.'/../files/landscape.jpg')
        ->fit('contain', 10, 20)
        ->format('jpg')
        ->quality(10)
        ->encode();

    $high = driverFor($driver)
        ->load(__DIR__.'/../files/landscape.jpg')
        ->fit('contain', 10, 20)
        ->format('jpg')
        ->quality(95)
        ->encode();

    expect(strlen($high))->toBeGreaterThanOrEqual(strlen($low));
})->with('drivers');

it('reports its name', function (string $driver): void {
    expect(driverFor($driver)->name())->toBe($driver);
})->with('drivers');

it('reports format support', function (string $driver): void {
    expect(driverFor($driver)->supportsFormat('png'))->toBeTrue()
        ->and(driverFor($driver)->supportsFormat('jpg'))->toBeTrue();
})->with('drivers');

it('resize keeps aspect ratio by width', function (string $driver): void {
    $bytes = driverFor($driver)
        ->load(__DIR__.'/../files/wide.png')
        ->resize(20, null)
        ->format('png')
        ->encode();

    [$w, $h] = dimensionsOf($bytes);

    expect($w)->toBe(20)->and($h)->toBe(10);
})->with('drivers');

it('sharpen returns the driver for chaining', function (string $driver): void {
    $image = driverFor($driver)->load(__DIR__.'/../files/wide.png')->sharpen(5);

    expect($image)->toBeInstanceOf(ImageDriver::class);
})->with('drivers');

it('resize keeps aspect ratio by height', function (string $driver): void {
    $bytes = driverFor($driver)
        ->load(__DIR__.'/../files/wide.png')
        ->resize(null, 10)
        ->format('png')
        ->encode();

    [$w, $h] = dimensionsOf($bytes);

    // 40x20 → height 10 → 20x10.
    expect($w)->toBe(20)->and($h)->toBe(10);
})->with('drivers');

it('encodes png and gif on gd', function (): void {
    if (! extension_loaded('gd')) {
        $this->markTestSkipped('ext-gd not available.');
    }

    $png = (new GdDriver)->load(__DIR__.'/../files/wide.png')->fit('contain', 10, 10)->format('png')->quality(50)->encode();
    $gif = (new GdDriver)->load(__DIR__.'/../files/wide.png')->fit('contain', 10, 10)->format('gif')->encode();

    expect((getimagesizefromstring($png)['mime'] ?? null))->toBe('image/png')
        ->and((getimagesizefromstring($gif)['mime'] ?? null))->toBe('image/gif');
});

it('handles a malformed background color on gd', function (): void {
    if (! extension_loaded('gd')) {
        $this->markTestSkipped('ext-gd not available.');
    }

    $bytes = (new GdDriver)
        ->load(__DIR__.'/../files/transparent.png')
        ->fit('contain', 10, 10)
        ->background('not-a-color')
        ->format('jpg')
        ->encode();

    expect((getimagesizefromstring($bytes)['mime'] ?? null))->toBe('image/jpeg');
});

it('reports gd avif support based on the build', function (): void {
    if (! extension_loaded('gd')) {
        $this->markTestSkipped('ext-gd not available.');
    }

    $info = gd_info();

    expect((new GdDriver)->supportsFormat('avif'))->toBe(($info['AVIF Support'] ?? false) === true)
        ->and((new GdDriver)->supportsFormat('tiff'))->toBeFalse();
});
