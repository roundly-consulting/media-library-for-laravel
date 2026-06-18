<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Buckets\MediaBucket;
use RoundlyConsulting\MediaLibrary\Exceptions\DiskDoesNotExist;
use RoundlyConsulting\MediaLibrary\Support\DiskResolver;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    $this->resolver = new DiskResolver;
});

it('falls back to the config disk for originals', function (): void {
    expect($this->resolver->resolveOriginalDisk(null, null))->toBe('public');
});

it('prefers the bucket disk over config', function (): void {
    $bucket = (new MediaBucket('avatar'))->useDisk('cold');

    expect($this->resolver->resolveOriginalDisk(null, $bucket))->toBe('cold');
});

it('prefers the explicit override over the bucket disk', function (): void {
    $bucket = (new MediaBucket('avatar'))->useDisk('cold');

    expect($this->resolver->resolveOriginalDisk('hot', $bucket))->toBe('hot');
});

it('resolves the variants disk from the bucket', function (): void {
    $bucket = (new MediaBucket('avatar'))->useDisk('cold')->storingVariantsOnDisk('hot');

    expect($this->resolver->resolveVariantsDisk(null, $bucket, 'cold'))->toBe('hot');
});

it('falls the variants disk back to the original disk', function (): void {
    expect($this->resolver->resolveVariantsDisk(null, null, 'cold'))->toBe('cold');
});

it('prefers the config variants disk over the original disk', function (): void {
    config()->set('media.variants_disk', 'hot');

    expect($this->resolver->resolveVariantsDisk(null, null, 'cold'))->toBe('hot');
});

it('prefers an explicit variants override over everything', function (): void {
    config()->set('media.variants_disk', 'hot');
    $bucket = (new MediaBucket('avatar'))->storingVariantsOnDisk('cold');

    expect($this->resolver->resolveVariantsDisk('public', $bucket, 'cold'))->toBe('public');
});

it('throws when a disk does not exist', function (): void {
    expect(fn () => $this->resolver->resolveOriginalDisk('ghost', null))
        ->toThrow(DiskDoesNotExist::class);
});

it('stores originals on cold and is configurable per bucket', function (): void {
    $user = TestUser::query()->create(['name' => 'Jane']);

    $media = $user->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaBucket('avatar');

    expect($media->disk)->toBe('cold');
    Storage::disk('cold')->assertExists($media->getPath());
    Storage::disk('public')->assertMissing($media->getPath());
});

it('respects a terminal disk override', function (): void {
    $user = TestUser::query()->create(['name' => 'Jane']);

    $media = $user->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaBucket('gallery', 'hot');

    expect($media->disk)->toBe('hot');
    Storage::disk('hot')->assertExists($media->getPath());
});
