<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Exceptions\InvalidVariant;
use RoundlyConsulting\MediaLibrary\Support\ImageDecodeGuard;
use RoundlyConsulting\MediaLibrary\Support\MediaConfig;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\ImagickDriver;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/** A file sniffed as a PNG whose header stops before its size. */
function brokenPng(): string
{
    $path = sys_get_temp_dir().'/media-broken-'.uniqid().'.png';
    file_put_contents($path, "\x89PNG\r\n\x1a\n\x00\x00\x00\x0dIHDR");

    return $path;
}

it('decodes raster types only', function (?string $mimeType, bool $decodes): void {
    expect(ImageDecodeGuard::decodes($mimeType))->toBe($decodes);
})->with([
    ['image/png', true],
    ['image/jpeg', true],
    ['image/webp', true],
    ['image/heic', true],
    ['image/svg+xml', false],
    ['application/pdf', false],
    [null, false],
]);

it('names the coder from the sniffed bytes and reports the header size', function (): void {
    $image = ImageDecodeGuard::inspect(__DIR__.'/../files/landscape.jpg');

    expect($image->mimeType)->toBe('image/jpeg')
        ->and($image->coder)->toBe('JPEG')
        ->and([$image->width, $image->height])->toBe([10, 20]);
});

it('refuses an image whose size cannot be read', function (): void {
    $path = brokenPng();

    try {
        expect(fn () => ImageDecodeGuard::inspect($path))->toThrow(InvalidVariant::class, 'dimensions cannot be read');
    } finally {
        @unlink($path);
    }
});

it('measures a type the header reader does not know through the given probe', function (): void {
    $path = brokenPng();

    try {
        $image = ImageDecodeGuard::inspect($path, static fn (string $coder): array => $coder === 'PNG' ? [3, 4] : [0, 0]);

        expect([$image->width, $image->height])->toBe([3, 4]);
    } finally {
        @unlink($path);
    }
});

it('refuses an image the imagick ping cannot read either', function (): void {
    $path = brokenPng();

    try {
        expect(fn () => (new ImagickDriver)->load($path))->toThrow(InvalidVariant::class, 'dimensions cannot be read');
    } finally {
        @unlink($path);
    }
})->skip(! extension_loaded('imagick'), 'needs ext-imagick');

it('lets any size through when media.max_image_pixels is null', function (): void {
    config()->set('media.max_image_pixels', null);

    $image = ImageDecodeGuard::inspect(__DIR__.'/../files/bomb.png');

    expect([$image->width, $image->height])->toBe([20000, 20000])
        ->and(MediaConfig::maxImagePixels())->toBeNull();
});

it('reads media.max_image_pixels strictly', function (): void {
    expect(MediaConfig::maxImagePixels())->toBe(50_000_000);

    config()->set('media.max_image_pixels', '1000');
    expect(MediaConfig::maxImagePixels())->toBe(1000);

    config()->set('media.max_image_pixels', 'lots');
    expect(fn () => MediaConfig::maxImagePixels())->toThrow(InvalidConfigurationException::class);

    config()->set('media.max_image_pixels', 0);
    expect(fn () => MediaConfig::maxImagePixels())->toThrow(InvalidConfigurationException::class);
});
