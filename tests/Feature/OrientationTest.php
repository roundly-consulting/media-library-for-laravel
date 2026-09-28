<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\OrientedJpeg;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

/**
 * A portrait-held phone photo: stored 32x64 with EXIF Orientation=6, displayed 64x32. The row
 * must record what a viewer sees, and everything derived from it must come out upright.
 */
beforeEach(function (): void {
    if (! extension_loaded('gd')) {
        $this->markTestSkipped('ext-gd builds the oriented fixtures.');
    }

    $this->phonePhoto = OrientedJpeg::path(6);
});

afterEach(function (): void {
    @unlink($this->phonePhoto);
});

it('records the upright dimensions of a rotated phone photo', function (): void {
    $media = TestUser::query()->create(['name' => 'Jane'])
        ->addMedia($this->phonePhoto)
        ->toMediaBucket('gallery');

    expect([$media->width, $media->height])->toBe([64, 32]);
});

it('generates upright variants from a rotated phone photo', function (): void {
    $media = TestUser::query()->create(['name' => 'Jane'])
        ->addMedia($this->phonePhoto)
        ->toMediaBucket('webphero');

    $bytes = (string) Storage::disk('public')->get($media->getPath('responsive-16'));
    $size = getimagesizefromstring($bytes);

    expect([$size[0] ?? 0, $size[1] ?? 0])->toBe([16, 8])
        ->and(OrientedJpeg::quadrantsOf($bytes))->toBe(OrientedJpeg::QUADRANTS);
});

it('sizes the responsive ladder from the upright width', function (): void {
    // Stored 32px wide but displayed 64px wide: the 64 rung is within the original, not an upscale.
    $media = TestUser::query()->create(['name' => 'Jane'])
        ->addMedia($this->phonePhoto)
        ->toMediaBucket('hero');

    expect(array_keys(array_filter($media->generated_variants ?? [])))
        ->toBe(['responsive-16', 'responsive-24', 'responsive-64']);
});

it('records the upright dimensions when a phone photo replaces the bytes', function (): void {
    $media = TestUser::query()->create(['name' => 'Jane'])
        ->addMedia(__DIR__.'/../files/sunrise.png')
        ->toMediaBucket('gallery');

    $media->replace($this->phonePhoto);

    expect([$media->width, $media->height])->toBe([64, 32]);
});
