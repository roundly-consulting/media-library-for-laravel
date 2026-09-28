<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

function pendingUser(): TestUser
{
    return TestUser::query()->create(['name' => 'Jane']);
}

it('overrides the original disk via the builder', function (): void {
    $media = pendingUser()->addMedia(UploadedFile::fake()->image('a.jpg'))
        ->useDisk('hot')
        ->toMediaBucket('gallery');

    expect($media->disk)->toBe('hot');
    Storage::disk('hot')->assertExists($media->getPath());
});

it('overrides the variants disk via the builder', function (): void {
    $media = pendingUser()->addMedia(UploadedFile::fake()->image('a.jpg'))
        ->storingVariantsOnDisk('hot')
        ->toMediaBucket('gallery');

    expect($media->variants_disk)->toBe('hot');
});

it('overrides visibility via the builder', function (): void {
    $media = pendingUser()->addMedia(UploadedFile::fake()->image('a.jpg'))
        ->withVisibility('private')
        ->toMediaBucket('gallery');

    expect($media->visibility)->toBe('private');
});

it('never moves or deletes a local source path', function (): void {
    $source = tempnam(sys_get_temp_dir(), 'src_').'.txt';
    file_put_contents($source, 'keep me');

    $media = pendingUser()->addMedia($source)->toMediaBucket('gallery');

    expect(is_file($source))->toBeTrue();
    Storage::disk('public')->assertExists($media->getPath());

    @unlink($source);
});

it('stores to a bucket on a specific disk', function (): void {
    $media = pendingUser()->addMedia(UploadedFile::fake()->image('a.jpg'))
        ->toMediaBucketOnDisk('gallery', 'hot');

    expect($media->disk)->toBe('hot');
});
