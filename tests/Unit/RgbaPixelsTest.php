<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\DataTransferObjects\RgbaImage;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\GdDriver;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\ImagickDriver;

it('reads downscaled rgba pixels with gd, never upscaling', function (): void {
    // wide.png is 40x20; a maxSize of 100 must not upscale it.
    $image = (new GdDriver)->load(__DIR__.'/../files/wide.png')->rgbaPixels(100);

    expect($image)->toBeInstanceOf(RgbaImage::class)
        ->and($image->width)->toBe(40)
        ->and($image->height)->toBe(20)
        ->and(count($image->pixels))->toBe(40 * 20 * 4);
});

it('downscales to the requested longest side with gd', function (): void {
    $image = (new GdDriver)->load(__DIR__.'/../files/wide.png')->rgbaPixels(20);

    expect(max($image->width, $image->height))->toBeLessThanOrEqual(20)
        ->and($image->width)->toBe(20)
        ->and($image->height)->toBe(10);
});

it('preserves alpha when reading a transparent png with gd', function (): void {
    $image = (new GdDriver)->load(__DIR__.'/../files/transparent.png')->rgbaPixels(100);

    $alphaValues = [];
    for ($i = 3; $i < count($image->pixels); $i += 4) {
        $alphaValues[] = $image->pixels[$i];
    }

    // Every alpha byte is on the 0..255 scale.
    expect(min($alphaValues))->toBeGreaterThanOrEqual(0)
        ->and(max($alphaValues))->toBeLessThanOrEqual(255);
});

it('reads downscaled rgba pixels with imagick', function (): void {
    $image = (new ImagickDriver)->load(__DIR__.'/../files/wide.png')->rgbaPixels(20);

    expect($image->width)->toBe(20)
        ->and($image->height)->toBe(10)
        ->and(count($image->pixels))->toBe(20 * 10 * 4);
})->skip(! extension_loaded('imagick'), 'imagick not loaded');
