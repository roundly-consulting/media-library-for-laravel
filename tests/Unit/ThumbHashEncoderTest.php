<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\DataTransferObjects\RgbaImage;
use RoundlyConsulting\MediaLibrary\Placeholders\ThumbHashEncoder;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\GdDriver;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\ImagickDriver;

/**
 * `sunrise.png` is the canonical ThumbHash reference image; the reference implementations
 * (evanw/thumbhash and the Go/Python ports) all encode it to this exact base64 string.
 */
const SUNRISE_THUMBHASH = '1QcSHQRnh493V4dIh4eXh1h4kJUI';

it('encodes the canonical reference image to the published ThumbHash (gd)', function (): void {
    $image = (new GdDriver)->load(__DIR__.'/../files/sunrise.png')->rgbaPixels(100);

    expect((new ThumbHashEncoder)->encode($image))->toBe(SUNRISE_THUMBHASH);
});

it('encodes the canonical reference image to the published ThumbHash (imagick)', function (): void {
    $image = (new ImagickDriver)->load(__DIR__.'/../files/sunrise.png')->rgbaPixels(100);

    expect((new ThumbHashEncoder)->encode($image))->toBe(SUNRISE_THUMBHASH);
})->skip(! extension_loaded('imagick'), 'imagick not loaded');

it('round-trips a ThumbHash back into a valid RGBA raster', function (): void {
    $decoded = (new ThumbHashEncoder)->decodeToRgba(SUNRISE_THUMBHASH);

    expect($decoded)->toBeInstanceOf(RgbaImage::class)
        ->and($decoded->width)->toBeGreaterThan(0)
        ->and($decoded->height)->toBeGreaterThan(0)
        ->and(count($decoded->pixels))->toBe($decoded->width * $decoded->height * 4);
});

it('exposes the approximate size and alpha flag of a hash', function (): void {
    [$lx, $ly, $hasAlpha] = (new ThumbHashEncoder)->decodeSize(SUNRISE_THUMBHASH);

    expect($lx)->toBeGreaterThan(0.0)
        ->and($ly)->toBeGreaterThan(0)
        ->and($hasAlpha)->toBe(0);
});

it('flags alpha and round-trips a transparent image', function (): void {
    // A 4x4 image: left half opaque red, right half fully transparent.
    $pixels = [];
    for ($y = 0; $y < 4; $y++) {
        for ($x = 0; $x < 4; $x++) {
            $pixels[] = 255;
            $pixels[] = 0;
            $pixels[] = 0;
            $pixels[] = $x < 2 ? 255 : 0;
        }
    }

    $encoder = new ThumbHashEncoder;
    $hash = $encoder->encode(new RgbaImage(4, 4, $pixels));

    [, , $hasAlpha] = $encoder->decodeSize($hash);

    expect($hasAlpha)->toBe(1);

    $decoded = $encoder->decodeToRgba($hash);
    expect(count($decoded->pixels))->toBe($decoded->width * $decoded->height * 4);
});

it('produces deterministic bytes for fixed pixels', function (): void {
    $pixels = [];
    for ($i = 0; $i < 16; $i++) {
        $pixels[] = 10 * $i % 255;
        $pixels[] = 5 * $i % 255;
        $pixels[] = 200;
        $pixels[] = 255;
    }

    $encoder = new ThumbHashEncoder;
    $image = new RgbaImage(4, 4, $pixels);

    expect($encoder->encode($image))->toBe($encoder->encode($image));
});

/*
 * Reference vectors: decoded by evanw/thumbhash's own js/thumbhash.js (thumbHashToRGBA). The
 * decoder read its AC factors from the header bytes and sized every non-alpha hash square, so
 * shape-only checks never saw that the picture was wrong.
 */
it('decodes a thumbhash exactly like the reference implementation', function (string $hash, int $width, int $height, array $first, array $centre, array $last): void {
    $decoded = (new ThumbHashEncoder)->decodeToRgba($hash);

    $pixel = static fn (int $x, int $y): array => array_slice($decoded->pixels, ($y * $decoded->width + $x) * 4, 4);
    $near = static function (array $actual, array $expected): void {
        foreach ($expected as $channel => $value) {
            expect(abs($actual[$channel] - $value))->toBeLessThanOrEqual(1);
        }
    };

    expect([$decoded->width, $decoded->height])->toBe([$width, $height]);

    $near($pixel(0, 0), $first);
    $near($pixel(intdiv($width, 2), intdiv($height, 2)), $centre);
    $near($pixel($width - 1, $height - 1), $last);
})->with([
    'the sunrise reference (portrait)' => [SUNRISE_THUMBHASH, 23, 32, [64, 77, 113, 255], [140, 109, 88, 255], [0, 4, 39, 255]],
    'a landscape gradient' => ['4PcJPJpwd3eAiHh4iHdwcAf3hw==', 32, 18, [0, 13, 166, 255], [135, 144, 125, 255], [230, 242, 113, 255]],
    'a portrait gradient with alpha' => ['o+iFGw4okLGoeIiQsQrpWXR3cHd3eIg=', 19, 32, [120, 136, 134, 9], [133, 139, 130, 151], [226, 224, 118, 244]],
]);
