<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\UuidTestUser;
use RoundlyConsulting\MediaLibrary\Variants\ResponsiveImageGenerator;

function uuidUser(string $name = 'Jane'): UuidTestUser
{
    return UuidTestUser::query()->create(['name' => $name]);
}

it('stores the owner uuid on model_id without coercing it to an integer', function (): void {
    $user = uuidUser();
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');

    expect($user->getKey())->toBeString()
        ->and($media->model_id)->toBe($user->getKey())
        ->and($media->model_type)->toBe($user->getMorphClass());

    // Re-read from the database: the stored value survives a round trip as the full uuid string.
    $fresh = Media::query()->findOrFail($media->id);
    expect($fresh->model_id)->toBe($user->getKey());
});

it('returns only the owner own media from getMedia', function (): void {
    $user = uuidUser();
    $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');

    expect($user->getMedia('gallery'))->toHaveCount(1);
});

it('does not leak media between two distinct uuid owners', function (): void {
    $jane = uuidUser('Jane');
    $john = uuidUser('John');

    $janeMedia = $jane->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');
    $johnMedia = $john->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');

    expect($jane->getKey())->not->toBe($john->getKey())
        ->and($jane->getMedia('gallery'))->toHaveCount(1)
        ->and($john->getMedia('gallery'))->toHaveCount(1)
        ->and($jane->getMedia('gallery')->first()?->id)->toBe($janeMedia->id)
        ->and($john->getMedia('gallery')->first()?->id)->toBe($johnMedia->id);
});

it('resolves the morphTo owner back to the correct uuid model', function (): void {
    $jane = uuidUser('Jane');
    $john = uuidUser('John');

    $media = $jane->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');

    $owner = $media->fresh()?->model;

    expect($owner)->toBeInstanceOf(UuidTestUser::class)
        ->and($owner?->getKey())->toBe($jane->getKey())
        ->and($owner?->getKey())->not->toBe($john->getKey());
});

it('resolves variant definitions for a uuid owner', function (): void {
    $user = uuidUser();
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('photos');

    $variants = $media->resolveVariants();

    expect($variants)->not->toBeEmpty()
        ->and($media->hasGeneratedVariant('thumb'))->toBeTrue();
});

it('generates responsive variants for a uuid owner', function (): void {
    $user = uuidUser();
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('hero');

    expect($media->hasGeneratedVariant(ResponsiveImageGenerator::variantName(16)))->toBeTrue()
        ->and($media->hasGeneratedVariant(ResponsiveImageGenerator::variantName(24)))->toBeTrue()
        ->and($media->hasGeneratedVariant(ResponsiveImageGenerator::variantName(64)))->toBeFalse();
});
