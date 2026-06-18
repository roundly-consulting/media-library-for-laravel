<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Placeholders\PlaceholderDataUri;
use RoundlyConsulting\MediaLibrary\Placeholders\ThumbHashEncoder;

it('returns null when there is no placeholder to render', function (): void {
    expect(app(PlaceholderDataUri::class)->fromPlaceholders([]))->toBeNull();
});

it('returns null when placeholder values are empty strings', function (): void {
    expect(app(PlaceholderDataUri::class)->fromPlaceholders(['thumbhash' => '', 'blurhash' => '']))
        ->toBeNull();
});

it('decodes a too-short ThumbHash without crashing', function (): void {
    $encoder = new ThumbHashEncoder;

    // '*' is not valid base64 → decodeBytes returns []; the decoders must degrade gracefully.
    [$lx, $ly, $hasAlpha] = $encoder->decodeSize('*');

    expect($lx)->toBeFloat()
        ->and($ly)->toBeInt()
        ->and($hasAlpha)->toBeInt();

    $image = $encoder->decodeToRgba('*');
    expect(count($image->pixels))->toBe($image->width * $image->height * 4);
});
