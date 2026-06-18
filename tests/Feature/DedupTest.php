<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Actions\DeleteMediaAction;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

function dedupUser(string $name = 'Jane'): TestUser
{
    return TestUser::query()->create(['name' => $name]);
}

it('stores identical bytes once for the same disk and visibility', function (): void {
    $user = dedupUser();

    $a = $user->addMedia(__DIR__.'/../files/pixel.png')->preservingOriginal()->toMediaBucket('gallery');
    $b = $user->addMedia(__DIR__.'/../files/pixel.png')->preservingOriginal()->toMediaBucket('gallery');

    // Two distinct rows...
    expect($a->id)->not->toBe($b->id)
        ->and($a->checksum)->not->toBeNull()
        ->and($b->checksum)->toBe($a->checksum);

    // ...sharing one physical original.
    expect($b->getPath())->toBe($a->getPath());
    expect(Storage::disk('public')->allFiles())->toHaveCount(1);

    // Both rows resolve to the same stored bytes.
    Storage::disk('public')->assertExists($a->getPath());
    Storage::disk('public')->assertExists($b->getPath());
    expect($a->getUrl())->toBe($b->getUrl());
});

it('stores a fresh copy on a different disk', function (): void {
    $user = dedupUser();

    $a = $user->addMedia(__DIR__.'/../files/pixel.png')->preservingOriginal()->toMediaBucket('gallery', 'public');
    $b = $user->addMedia(__DIR__.'/../files/pixel.png')->preservingOriginal()->toMediaBucket('gallery', 'cold');

    expect($b->checksum)->toBe($a->checksum)
        ->and($b->disk)->toBe('cold');

    Storage::disk('public')->assertExists($a->getPath());
    Storage::disk('cold')->assertExists($b->getPath());
    expect(Storage::disk('public')->allFiles())->toHaveCount(1)
        ->and(Storage::disk('cold')->allFiles())->toHaveCount(1);
});

it('stores a fresh copy for a different visibility', function (): void {
    $user = dedupUser();

    $a = $user->addMedia(__DIR__.'/../files/pixel.png')->preservingOriginal()->toMediaBucket('gallery');
    $b = $user->addMedia(__DIR__.'/../files/pixel.png')
        ->preservingOriginal()
        ->withVisibility('private')
        ->toMediaBucket('gallery');

    expect($b->checksum)->toBe($a->checksum)
        ->and($a->visibility)->toBe('public')
        ->and($b->visibility)->toBe('private')
        ->and($b->getPath())->not->toBe($a->getPath());

    expect(Storage::disk('public')->allFiles())->toHaveCount(2);
});

it('stores a fresh copy when dedup is disabled', function (): void {
    config()->set('media.deduplicate', false);

    $user = dedupUser();

    $a = $user->addMedia(__DIR__.'/../files/pixel.png')->preservingOriginal()->toMediaBucket('gallery');
    $b = $user->addMedia(__DIR__.'/../files/pixel.png')->preservingOriginal()->toMediaBucket('gallery');

    expect($b->getPath())->not->toBe($a->getPath());
    expect(Storage::disk('public')->allFiles())->toHaveCount(2);
});

it('keeps the shared original until the last referrer is deleted', function (): void {
    $user = dedupUser();

    $a = $user->addMedia(__DIR__.'/../files/pixel.png')->preservingOriginal()->toMediaBucket('gallery');
    $b = $user->addMedia(__DIR__.'/../files/pixel.png')->preservingOriginal()->toMediaBucket('gallery');

    $shared = $a->getPath();

    app(DeleteMediaAction::class)->execute($a);

    // First delete leaves the shared file for the remaining referrer.
    Storage::disk('public')->assertExists($shared);
    expect(Media::find($b->id))->not->toBeNull();

    app(DeleteMediaAction::class)->execute($b->fresh());

    // Last referrer gone -> the physical original is removed.
    Storage::disk('public')->assertMissing($shared);
});

it('removes the original when a sole referrer is deleted', function (): void {
    $user = dedupUser();

    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $path = $media->getPath();

    Storage::disk('public')->assertExists($path);

    app(DeleteMediaAction::class)->execute($media);

    Storage::disk('public')->assertMissing($path);
});

it('copies a still-shared original on move instead of deleting the source', function (): void {
    $user = dedupUser();

    $a = $user->addMedia(__DIR__.'/../files/pixel.png')->preservingOriginal()->toMediaBucket('gallery');
    $b = $user->addMedia(__DIR__.'/../files/pixel.png')->preservingOriginal()->toMediaBucket('gallery');

    $sharedPath = $a->getPath();

    $b->moveToDisk('cold');

    // The source survives because A still references it...
    Storage::disk('public')->assertExists($sharedPath);
    // ...and B now has its own copy on the target disk.
    Storage::disk('cold')->assertExists($b->fresh()?->getPath());
    expect($b->fresh()?->disk)->toBe('cold');
});

it('relocates and deletes the source when a sole referrer moves', function (): void {
    $user = dedupUser();

    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $sourcePath = $media->getPath();

    $media->moveToDisk('cold');

    Storage::disk('public')->assertMissing($sourcePath);
    Storage::disk('cold')->assertExists($media->fresh()?->getPath());
});

it('keeps variants per-row even when originals are deduped', function (): void {
    $user = dedupUser();

    // The `covers` bucket generates a `small` variant; identical originals dedup the original
    // but each row generates its own variants under its own uuid.
    $a = $user->addMedia(__DIR__.'/../files/wide.png')->preservingOriginal()->toMediaBucket('covers');
    $b = $user->addMedia(__DIR__.'/../files/wide.png')->preservingOriginal()->toMediaBucket('covers');

    expect($b->getPath())->toBe($a->getPath());
    expect($a->getPath('small'))->not->toBe($b->getPath('small'));

    Storage::disk('public')->assertExists($a->getPath('small'));
    Storage::disk('public')->assertExists($b->getPath('small'));

    // Deleting one row removes only its own variant, never the other's or the shared original.
    app(DeleteMediaAction::class)->execute($a);

    Storage::disk('public')->assertMissing($a->getPath('small'));
    Storage::disk('public')->assertExists($b->getPath('small'));
    Storage::disk('public')->assertExists($b->getPath());
});
