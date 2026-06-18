<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Exceptions\ChecksumMismatch;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

function integrityUser(string $name = 'Jane'): TestUser
{
    return TestUser::query()->create(['name' => $name]);
}

it('records a checksum on add', function (): void {
    $user = integrityUser();

    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    expect($media->checksum)->not->toBeNull()
        ->and($media->checksum)->toHaveLength(64); // sha256 hex
});

it('verifies a stored original against its checksum', function (): void {
    $user = integrityUser();

    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    expect($media->verifyIntegrity())->toBeTrue();
});

it('reports drift when the stored bytes change', function (): void {
    $user = integrityUser();

    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    Storage::disk('public')->put($media->getPath(), 'tampered bytes');

    expect($media->verifyIntegrity())->toBeFalse();
});

it('returns false from verifyIntegrity without a baseline', function (): void {
    $user = integrityUser();

    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $media->checksum = null;

    expect($media->verifyIntegrity())->toBeFalse();
});

it('returns false from verifyIntegrity when the file is missing', function (): void {
    $user = integrityUser();

    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    Storage::disk('public')->delete($media->getPath());

    expect($media->verifyIntegrity())->toBeFalse();
});

it('does not verify on read by default', function (): void {
    $user = integrityUser();

    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    Storage::disk('public')->put($media->getPath(), 'tampered bytes');

    // Default config: no exception even though the bytes drifted.
    expect($media->getStream())->toBeResource();
});

it('throws ChecksumMismatch on read when verification is enabled', function (): void {
    config()->set('media.verify_checksum_on_read', true);

    $user = integrityUser();

    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    Storage::disk('public')->put($media->getPath(), 'tampered bytes');

    expect(fn (): mixed => $media->getStream())->toThrow(ChecksumMismatch::class);
});

it('skips read verification when the media has no baseline', function (): void {
    config()->set('media.verify_checksum_on_read', true);

    $user = integrityUser();
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    // No baseline recorded → nothing to compare against, so the read is not blocked.
    $media->checksum = null;
    $media->save();

    expect($media->getStream())->toBeResource();
});

it('passes read verification when the bytes are intact', function (): void {
    config()->set('media.verify_checksum_on_read', true);

    $user = integrityUser();

    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    expect($media->getStream())->toBeResource();
});
