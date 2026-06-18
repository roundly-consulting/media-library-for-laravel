<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Buckets\MediaBucket;
use RoundlyConsulting\MediaLibrary\Variants\VariantRegistrar;

it('configures storage and visibility fluently', function (): void {
    $bucket = (new MediaBucket('avatar'))
        ->useDisk('cold')
        ->storingVariantsOnDisk('hot')
        ->singleFile()
        ->private();

    expect($bucket->name)->toBe('avatar')
        ->and($bucket->getDisk())->toBe('cold')
        ->and($bucket->getVariantsDisk())->toBe('hot')
        ->and($bucket->isSingleFile())->toBeTrue()
        ->and($bucket->getVisibility())->toBe('private');
});

it('toggles back to public visibility', function (): void {
    $bucket = (new MediaBucket('avatar'))->private()->public();

    expect($bucket->getVisibility())->toBe('public');
});

it('sets an explicit visibility', function (): void {
    $bucket = (new MediaBucket('avatar'))->withVisibility('private');

    expect($bucket->getVisibility())->toBe('private');
});

it('accepts any mime type when no allowlist is set', function (): void {
    $bucket = new MediaBucket('gallery');

    expect($bucket->accepts('image/png'))->toBeTrue()
        ->and($bucket->accepts(null))->toBeTrue();
});

it('enforces a mime allowlist', function (): void {
    $bucket = (new MediaBucket('avatar'))->acceptsMimeTypes(['image/png']);

    expect($bucket->accepts('image/png'))->toBeTrue()
        ->and($bucket->accepts('image/gif'))->toBeFalse()
        ->and($bucket->accepts(null))->toBeFalse()
        ->and($bucket->getAcceptedMimeTypes())->toBe(['image/png']);
});

it('stores fallback url and path', function (): void {
    $bucket = (new MediaBucket('avatar'))
        ->useFallbackUrl('https://x/y.png')
        ->useFallbackPath('/tmp/y.png');

    expect($bucket->getFallbackUrl())->toBe('https://x/y.png')
        ->and($bucket->getFallbackPath())->toBe('/tmp/y.png');
});

it('defaults single file to false', function (): void {
    expect((new MediaBucket('gallery'))->isSingleFile())->toBeFalse();
});

it('reports no variants by default', function (): void {
    $bucket = new MediaBucket('gallery');

    expect($bucket->hasVariants())->toBeFalse()
        ->and($bucket->variants()->isEmpty())->toBeTrue();
});

it('registers and memoizes variants', function (): void {
    $bucket = (new MediaBucket('photos'))->registerVariants(function (VariantRegistrar $v): void {
        $v->add('thumb')->width(120)->format('webp');
        $v->add('display')->width(800);
    });

    expect($bucket->hasVariants())->toBeTrue()
        ->and($bucket->variants()->names())->toBe(['thumb', 'display']);

    // Calling again returns the same memoized collection.
    expect($bucket->variants())->toBe($bucket->variants());
});
