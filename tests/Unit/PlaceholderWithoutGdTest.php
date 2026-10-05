<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Placeholders\BlurHashEncoder;
use RoundlyConsulting\MediaLibrary\Placeholders\PlaceholderDataUri;
use RoundlyConsulting\MediaLibrary\Placeholders\ThumbHashEncoder;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

/*
 * Imagick alone is enough to compute placeholders, and the docs say either extension will do —
 * so rendering them back into a data URI must not need GD. With neither there is simply none.
 */

/** A renderer that sees only the listed extensions. */
function placeholderRendererWith(string ...$extensions): PlaceholderDataUri
{
    return new PlaceholderDataUri(
        new ThumbHashEncoder,
        new BlurHashEncoder,
        static fn (string $extension): bool => in_array($extension, $extensions, true),
    );
}

it('renders the placeholder with imagick when gd is unavailable', function (): void {
    $uri = placeholderRendererWith('imagick')->fromPlaceholders(['thumbhash' => '1QcSHQRnh493V4dIh4eXh1h4kJUI']);

    expect($uri)->toStartWith('data:image/png;base64,');

    $png = new Imagick;
    $png->readImageBlob((string) base64_decode(substr((string) $uri, strlen('data:image/png;base64,'))));
    $first = $png->getImagePixelColor(0, 0)->getColor();

    expect([$png->getImageWidth(), $png->getImageHeight()])->toBe([23, 32])
        ->and([$first['r'], $first['g'], $first['b']])->toBe([64, 77, 113]);
})->skip(! extension_loaded('imagick'), 'needs ext-imagick');

it('draws the same placeholder with either extension', function (): void {
    $placeholders = ['blurhash' => 'LEHV6nWB2yk8pyo0adR*.7kCMdnj'];

    $decode = static function (?string $uri): array {
        $png = new Imagick;
        $png->readImageBlob((string) base64_decode(substr((string) $uri, strlen('data:image/png;base64,'))));

        return $png->exportImagePixels(0, 0, 32, 32, 'RGBA', Imagick::PIXEL_CHAR);
    };

    expect($decode(placeholderRendererWith('imagick')->fromPlaceholders($placeholders)))
        ->toBe($decode(placeholderRendererWith('gd')->fromPlaceholders($placeholders)));
})->skip(! extension_loaded('imagick') || ! extension_loaded('gd'), 'needs ext-imagick and ext-gd');

it('has no data uri, and an img tag without one, when neither extension is available', function (): void {
    app()->instance(PlaceholderDataUri::class, placeholderRendererWith());

    $user = TestUser::query()->create(['name' => 'Ada']);
    $media = $user->addMedia(__DIR__.'/../files/sunrise.png')->toMediaBucket('banner');

    expect($media->thumbhash())->not->toBeNull()
        ->and($media->placeholderDataUri())->toBeNull()
        ->and(MediaLibrary::for($user)->first('banner')?->responsiveImage())->toStartWith('<img')->not->toContain('background-image');
});
