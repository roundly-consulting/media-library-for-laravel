<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\DataTransferObjects\RgbaImage;
use RoundlyConsulting\MediaLibrary\Placeholders\BlurHashEncoder;

function solidImage(int $r, int $g, int $b, int $a = 255): RgbaImage
{
    $pixels = [];

    for ($i = 0; $i < 16; $i++) {
        $pixels[] = $r;
        $pixels[] = $g;
        $pixels[] = $b;
        $pixels[] = $a;
    }

    return new RgbaImage(4, 4, $pixels);
}

/**
 * Solid-colour Blurhashes are fully determined by the spec (all AC components collapse to the
 * "zero" code `fQ`, and the DC is the base83-encoded sRGB average), so these are exact,
 * hand-derivable reference vectors.
 */
it('encodes a solid white image to its reference Blurhash', function (): void {
    expect((new BlurHashEncoder)->encode(solidImage(255, 255, 255), 1, 1))->toBe('00TSUA');
});

it('encodes a solid black image to its reference Blurhash', function (): void {
    expect((new BlurHashEncoder)->encode(solidImage(0, 0, 0), 1, 1))->toBe('000000');
});

it('encodes a solid red image to its reference Blurhash', function (): void {
    expect((new BlurHashEncoder)->encode(solidImage(255, 0, 0), 1, 1))->toBe('00TI:j');
});

it('encodes a multi-component hash with the size flag and DC reference', function (): void {
    // 4x3 components → size flag = (4-1) + (3-1)*9 = 21 → base83 'L'. The DC encodes the
    // solid red average (`TI:j`), matching the exact 1x1 vector's DC bytes.
    $hash = (new BlurHashEncoder)->encode(solidImage(255, 0, 0), 4, 3);

    expect($hash[0])->toBe('L')
        ->and(substr($hash, 2, 4))->toBe('TI:j')
        ->and(strlen($hash))->toBe(6 + 2 * 11);
});

it('round-trips a solid colour back to that colour', function (): void {
    $encoder = new BlurHashEncoder;

    $white = $encoder->decodeToRgba('00TSUA', 4, 4);
    expect(array_slice($white->pixels, 0, 4))->toBe([255, 255, 255, 255]);

    $black = $encoder->decodeToRgba('000000', 4, 4);
    expect(array_slice($black->pixels, 0, 4))->toBe([0, 0, 0, 255]);
});

it('clamps component counts into the valid 1..9 range', function (): void {
    $hash = (new BlurHashEncoder)->encode(solidImage(0, 0, 0), 99, 0);

    // size flag stays a single base83 digit within bounds
    expect(strlen($hash))->toBeGreaterThanOrEqual(6);
});

it('encodes a two-band image with non-zero AC components', function (): void {
    $pixels = [];
    for ($y = 0; $y < 8; $y++) {
        for ($x = 0; $x < 8; $x++) {
            $top = $y < 4;
            $pixels[] = $top ? 200 : 40;
            $pixels[] = 40;
            $pixels[] = $top ? 40 : 200;
            $pixels[] = 255;
        }
    }

    $encoder = new BlurHashEncoder;
    $hash = $encoder->encode(new RgbaImage(8, 8, $pixels), 4, 3);

    // A gradient produces real AC terms (not the all-`fQ` solid pattern).
    expect(substr($hash, 6))->not->toBe(str_repeat('fQ', 11));

    $decoded = $encoder->decodeToRgba($hash, 8, 8);
    $top = array_slice($decoded->pixels, 0, 3);
    $bottom = array_slice($decoded->pixels, (7 * 8) * 4, 3);

    // Relative to each other the bands keep their hue: the top is redder, the bottom bluer.
    expect($top[0])->toBeGreaterThan($bottom[0])
        ->and($bottom[2])->toBeGreaterThan($top[2]);
});
