<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

function placeholderUser(): TestUser
{
    return TestUser::query()->create(['name' => 'Jane']);
}

it('extracts pixel dimensions on add for an image', function (): void {
    $media = placeholderUser()->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');

    expect($media->width)->toBe(40)
        ->and($media->height)->toBe(20);
});

it('leaves dimensions null for non-image media', function (): void {
    $media = placeholderUser()
        ->addMediaFromString('plain text')
        ->usingFileName('note.txt')
        ->toMediaBucket('gallery');

    expect($media->width)->toBeNull()
        ->and($media->height)->toBeNull();
});

it('computes both placeholders on add for an image', function (): void {
    $media = placeholderUser()->addMedia(__DIR__.'/../files/sunrise.png')->toMediaBucket('gallery');

    expect($media->thumbhash())->toBe('1QcSHQRnh493V4dIh4eXh1h4kJUI')
        ->and($media->blurhash())->toBeString()->not->toBe('')
        ->and($media->placeholder())->toHaveKeys(['thumbhash', 'blurhash']);
});

it('skips placeholders for non-image media', function (): void {
    $media = placeholderUser()
        ->addMediaFromString('plain text')
        ->usingFileName('note.txt')
        ->toMediaBucket('gallery');

    expect($media->placeholder())->toBe([])
        ->and($media->thumbhash())->toBeNull()
        ->and($media->blurhash())->toBeNull()
        ->and($media->placeholderDataUri())->toBeNull();
});

it('respects the thumbhash toggle', function (): void {
    config()->set('media.placeholders.thumbhash', false);

    $media = placeholderUser()->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');

    expect($media->thumbhash())->toBeNull()
        ->and($media->blurhash())->not->toBeNull();
});

it('respects the blurhash toggle', function (): void {
    config()->set('media.placeholders.blurhash', false);

    $media = placeholderUser()->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');

    expect($media->blurhash())->toBeNull()
        ->and($media->thumbhash())->not->toBeNull();
});

it('stores nothing when both placeholder types are disabled', function (): void {
    config()->set('media.placeholders.thumbhash', false);
    config()->set('media.placeholders.blurhash', false);

    $media = placeholderUser()->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');

    expect($media->placeholder())->toBe([]);
});

it('decodes a placeholder into a valid tiny PNG data URI', function (): void {
    $media = placeholderUser()->addMedia(__DIR__.'/../files/sunrise.png')->toMediaBucket('gallery');

    $uri = $media->placeholderDataUri();

    expect($uri)->toStartWith('data:image/png;base64,');

    $binary = base64_decode(substr((string) $uri, strlen('data:image/png;base64,')), true);
    $info = getimagesizefromstring((string) $binary);

    expect($info)->not->toBeFalse()
        ->and($info[2] ?? 0)->toBe(IMAGETYPE_PNG)
        ->and($info[0] ?? 0)->toBeGreaterThan(0);
});

it('falls back to blurhash for the data uri when thumbhash is absent', function (): void {
    config()->set('media.placeholders.thumbhash', false);

    $media = placeholderUser()->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');

    expect($media->thumbhash())->toBeNull()
        ->and($media->placeholderDataUri())->toStartWith('data:image/png;base64,');
});
