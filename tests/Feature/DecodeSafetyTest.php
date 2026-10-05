<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Exceptions\InvalidVariant;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\GdDriver;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\ImagickDriver;

/*
 * Every image upload is decoded for its placeholders, and bucket images for their variants. An
 * untrusted file must not get to pick what that costs or what it reads: a 48 KB PNG declaring
 * 20000x20000 decodes to gigabytes, and ImageMagick left to choose the coder renders an SVG — one
 * that can pull a file off the server into a public thumbnail.
 */

const BOMB_PNG = __DIR__.'/../files/bomb.png';

it('skips decoding an image larger than media.max_image_pixels, before any decode', function (string $driver): void {
    config()->set('media.image_driver', $driver);
    config()->set('media.max_image_pixels', 799); // wide.png is 40x20 = 800 pixels

    $media = MediaLibrary::add(__DIR__.'/../files/wide.png')->toBucket('library');

    expect($media->placeholders)->toBeNull()
        ->and([$media->width, $media->height])->toBe([40, 20]);
    Storage::disk('public')->assertExists($media->getPath());
})->with(['gd', 'imagick']);

it('stores a decompression bomb without decoding it', function (string $driver): void {
    config()->set('media.image_driver', $driver);

    $media = MediaLibrary::add(BOMB_PNG)->toBucket('library');

    expect($media->placeholders)->toBeNull()
        ->and([$media->width, $media->height])->toBe([20000, 20000]);
})->with(['gd', 'imagick']);

it('refuses to load an image over the pixel cap into either driver', function (string $driver): void {
    $load = fn () => ($driver === 'gd' ? new GdDriver : new ImagickDriver)->load(BOMB_PNG);

    expect($load)->toThrow(InvalidVariant::class, '20000x20000');
})->with(['gd', 'imagick']);

it('never decodes an SVG into variants or placeholders', function (): void {
    config()->set('media.image_driver', 'imagick');

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><rect width="40" height="40" fill="blue"/></svg>';
    $user = TestUser::query()->create(['name' => 'Ada']);

    $media = $user->addMediaFromString($svg)->toMediaBucket('covers');

    expect($media->mime_type)->toBe('image/svg+xml')
        ->and($media->generatedVariants())->toBe([])
        ->and($media->placeholders)->toBeNull();
    Storage::disk('public')->assertMissing($media->getPathForVariantsDirectory().'small.jpg');
});

it('refuses to load an SVG into the imagick driver, whatever the file is called', function (): void {
    $path = sys_get_temp_dir().'/media-svg-'.uniqid().'.png';
    file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg" width="4" height="4"><rect width="4" height="4"/></svg>');

    try {
        expect(fn () => (new ImagickDriver)->load($path))->toThrow(InvalidVariant::class, 'image/svg+xml');
    } finally {
        @unlink($path);
    }
})->skip(! extension_loaded('imagick'), 'needs ext-imagick');
