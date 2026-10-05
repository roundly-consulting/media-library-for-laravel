<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Exceptions\FileCannotBeWritten;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\EdgeCaseUser;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

/*
 * A disk configured with `'throw' => false` reports a refused write (a full bucket, a denied
 * policy) as a plain `false`. Every relocation must check it: committing the new disk/path and
 * deleting the source after a failed write destroys the only copy of the file.
 */

it('refuses to move a media onto a disk that fails the write, keeping the row and the source', function (): void {
    $user = TestUser::query()->create(['name' => 'Ada']);
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $path = $media->getPath();

    expect(fn () => $media->moveToDisk('flaky'))->toThrow(FileCannotBeWritten::class);

    $fresh = Media::query()->findOrFail($media->id);

    expect($fresh->disk)->toBe('public')
        ->and($fresh->getPath())->toBe($path);
    Storage::disk('public')->assertExists($path);
});

it('refuses to bind a draft into a bucket whose disk fails the write, keeping the draft and its file', function (): void {
    $draft = MediaLibrary::draft(__DIR__.'/../files/pixel.png')->toBucket();
    $token = (string) $draft->draft_token;
    $path = $draft->getPath();

    $owner = EdgeCaseUser::query()->create(['name' => 'Ada']);

    expect(fn () => MediaLibrary::for($owner)->bindDraft($token, 'unwritable'))->toThrow(FileCannotBeWritten::class);

    $fresh = Media::query()->findOrFail($draft->id);

    expect($fresh->disk)->toBe('public')
        ->and($fresh->draft_token)->toBe($token)
        ->and($fresh->model_id)->toBeNull();
    Storage::disk('public')->assertExists($path);
});

it('refuses to move variants onto a disk that fails the write, keeping their records and files', function (): void {
    Bus::fake();

    $user = TestUser::query()->create(['name' => 'Ada']);
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('photos');
    $thumb = $media->getPath('thumb');

    expect(fn () => $media->moveVariantsToDisk('flaky'))->toThrow(FileCannotBeWritten::class);

    $fresh = Media::query()->findOrFail($media->id);

    expect($fresh->generatedVariant('thumb')?->disk)->toBe('hot')
        ->and($fresh->variants_disk)->toBe('hot');
    Storage::disk('hot')->assertExists($thumb);
});

it('refuses to copy a media onto a disk that fails the write, creating no row', function (): void {
    $user = TestUser::query()->create(['name' => 'Ada']);
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    expect(fn () => $media->copy($user, 'gallery', 'flaky'))->toThrow(FileCannotBeWritten::class);

    expect(Media::query()->count())->toBe(1);
});

it('takes back the copies it already wrote when a later variant write fails mid-move', function (): void {
    $user = TestUser::query()->create(['name' => 'Ada']);
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    expect(array_keys($media->generatedVariants()))->toContain('small', 'watermark');

    expect(fn () => $media->moveToDisk('flaky-variants'))->toThrow(FileCannotBeWritten::class);

    expect(Media::query()->findOrFail($media->id)->disk)->toBe('public')
        ->and(Storage::disk('flaky-variants')->allFiles())->toBe([]);
    Storage::disk('public')->assertExists([$media->getPath(), $media->getPath('small'), $media->getPath('watermark')]);
});

it('takes back the variant copies it already wrote when a later one fails', function (): void {
    $user = TestUser::query()->create(['name' => 'Ada']);
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    expect(fn () => $media->moveVariantsToDisk('flaky-variants'))->toThrow(FileCannotBeWritten::class);

    expect(Storage::disk('flaky-variants')->allFiles())->toBe([])
        ->and(Media::query()->findOrFail($media->id)->generatedVariant('small')?->disk)->toBe('public');
    Storage::disk('public')->assertExists($media->getPath('small'));
});

it('still moves a media whose variant file has already gone missing', function (): void {
    $user = TestUser::query()->create(['name' => 'Ada']);
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');
    Storage::disk('public')->delete($media->getPath('small'));

    $media->moveToDisk('cold');

    expect($media->fresh()?->disk)->toBe('cold')
        ->and($media->fresh()?->generatedVariant('small')?->disk)->toBe('cold');
    Storage::disk('cold')->assertExists($media->getPath());
});

it('still moves the variants of a media when one variant file has already gone missing', function (): void {
    $user = TestUser::query()->create(['name' => 'Ada']);
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');
    Storage::disk('public')->delete($media->getPath('small'));

    $media->moveVariantsToDisk('cold');

    expect($media->fresh()?->variants_disk)->toBe('cold')
        ->and($media->fresh()?->generatedVariant('small')?->disk)->toBe('cold');
    Storage::disk('cold')->assertExists($media->getPath('watermark'));
});

it('renders afresh a variant whose file could not be copied', function (): void {
    $jane = TestUser::query()->create(['name' => 'Jane']);
    $john = TestUser::query()->create(['name' => 'John']);
    $media = $jane->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');
    Storage::disk('public')->delete($media->getPath('small'));

    $copy = $media->copy($john, 'covers');

    expect($copy->hasGeneratedVariant('small'))->toBeTrue();
    Storage::disk('public')->assertExists($copy->getPath('small'));
});

it('refuses to bind a draft when the disk refuses the visibility change in place', function (): void {
    $draft = MediaLibrary::draft(__DIR__.'/../files/pixel.png')->useDisk('sticky')->toBucket();
    $owner = EdgeCaseUser::query()->create(['name' => 'Ada']);

    expect(fn () => MediaLibrary::for($owner)->bindDraft((string) $draft->draft_token, 'sticky'))
        ->toThrow(FileCannotBeWritten::class);

    $fresh = Media::query()->findOrFail($draft->id);

    expect($fresh->visibility)->toBe('public')
        ->and($fresh->draft_token)->toBe($draft->draft_token);
});

it('refuses to bind a draft when the bucket disk refuses the copy visibility, taking the copy back', function (): void {
    $draft = MediaLibrary::draft(__DIR__.'/../files/pixel.png')->toBucket();
    $owner = EdgeCaseUser::query()->create(['name' => 'Ada']);

    expect(fn () => MediaLibrary::for($owner)->bindDraft((string) $draft->draft_token, 'sticky'))
        ->toThrow(FileCannotBeWritten::class);

    expect(Storage::disk('sticky')->allFiles())->toBe([])
        ->and(Media::query()->findOrFail($draft->id)->disk)->toBe('public');
    Storage::disk('public')->assertExists($draft->getPath());
});
