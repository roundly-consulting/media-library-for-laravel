<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

function commandUser(string $name = 'Jane'): TestUser
{
    return TestUser::query()->create(['name' => $name]);
}

it('regenerates variants for all media', function (): void {
    $user = commandUser();
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    // Wipe a generated variant file and clear its flag to simulate a missing derivative.
    Storage::disk('public')->delete($media->getPath('small'));
    $media->generated_variants = [];
    $media->save();

    $this->artisan('media:regenerate')->assertSuccessful();

    Storage::disk('public')->assertExists($media->getPath('small'));
});

it('regenerates only the listed media ids', function (): void {
    $user = commandUser();
    $kept = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');
    $other = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    foreach ([$kept, $other] as $media) {
        Storage::disk('public')->delete($media->getPath('small'));
        $media->generated_variants = [];
        $media->save();
    }

    $this->artisan('media:regenerate', ['--ids' => (string) $kept->id])->assertSuccessful();

    Storage::disk('public')->assertExists($kept->getPath('small'));
    Storage::disk('public')->assertMissing($other->getPath('small'));
});

it('regenerates only the named variants', function (): void {
    $user = commandUser();
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    Storage::disk('public')->delete($media->getPath('small'));
    Storage::disk('public')->delete($media->getPath('keepformat'));
    $media->generated_variants = [];
    $media->save();

    $this->artisan('media:regenerate', ['--only' => 'small'])->assertSuccessful();

    Storage::disk('public')->assertExists($media->getPath('small'));
    // 'keepformat' was not requested, so it stays missing.
    $media->refresh();
    expect($media->hasGeneratedVariant('keepformat'))->toBeFalse();
});

it('skips already-generated variants unless forced', function (): void {
    $user = commandUser();
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    // Remove the file but keep the generated flag → without --force it is skipped.
    Storage::disk('public')->delete($media->getPath('small'));

    $this->artisan('media:regenerate', ['--only' => 'small'])->assertSuccessful();
    Storage::disk('public')->assertMissing($media->getPath('small'));

    // With --force it is regenerated even though the flag was set.
    $this->artisan('media:regenerate', ['--only' => 'small', '--force' => true])->assertSuccessful();
    Storage::disk('public')->assertExists($media->getPath('small'));
});

it('filters regeneration by model type', function (): void {
    $user = commandUser();
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    Storage::disk('public')->delete($media->getPath('small'));
    $media->generated_variants = [];
    $media->save();

    $this->artisan('media:regenerate', ['model' => 'nonexistent\\Model'])->assertSuccessful();
    Storage::disk('public')->assertMissing($media->getPath('small'));

    $this->artisan('media:regenerate', ['model' => $user->getMorphClass()])->assertSuccessful();
    Storage::disk('public')->assertExists($media->getPath('small'));
});

it('cleans orphaned variant files but leaves known ones', function (): void {
    $user = commandUser();
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    $orphan = $media->getPathForVariantsDirectory().'orphan.webp';
    Storage::disk('public')->put($orphan, 'junk');

    $this->artisan('media:clean')->assertSuccessful();

    Storage::disk('public')->assertMissing($orphan);
    // Known variant survives.
    Storage::disk('public')->assertExists($media->getPath('small'));
});

it('cleans orphaned variant files on a separate variants disk', function (): void {
    Bus::fake();

    $user = commandUser();
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('photos');

    $orphan = $media->getPathForVariantsDirectory().'stale.webp';
    Storage::disk('hot')->put($orphan, 'junk');

    $this->artisan('media:clean')->assertSuccessful();

    Storage::disk('hot')->assertMissing($orphan);
    Storage::disk('hot')->assertExists($media->getPath('thumb'));
});

it('skips media without a variants directory on clean', function (): void {
    $user = commandUser();
    // gallery has no variants → no variants directory exists on disk.
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    $this->artisan('media:clean')->assertSuccessful();

    Storage::disk('public')->assertExists($media->getPath());
});

it('leaves soft-deleted media untouched on clean', function (): void {
    $user = commandUser();
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    $media->delete();

    $this->artisan('media:clean')->assertSuccessful();

    // Soft-deleted media keeps its file.
    Storage::disk('public')->assertExists($media->getPath());
});

it('clears a model bucket', function (): void {
    $user = commandUser();
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    $this->artisan('media:clear', ['model' => $user->getMorphClass(), 'bucket' => 'gallery'])
        ->assertSuccessful();

    expect(Media::query()->count())->toBe(0);
    Storage::disk('public')->assertMissing($media->getPath());
});

it('clears a global bucket', function (): void {
    $logo = MediaLibrary::add(__DIR__.'/../files/pixel.png')->toBucket('brand');
    $user = commandUser();
    $owned = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    $this->artisan('media:clear', ['bucket' => 'brand'])->assertSuccessful();

    // Global brand media gone, the model's media untouched.
    expect(Media::query()->find($logo->id))->toBeNull()
        ->and(Media::query()->find($owned->id))->not->toBeNull();
});
