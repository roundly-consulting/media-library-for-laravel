<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Exceptions\MediaOwnerNotSaved;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

/*
 * An owner that was never saved has no key, so its media were stored with `model_id` NULL — and
 * scoping to it compiled to `model_id IS NULL`: one unsaved owner's single-file add deleted
 * another unsaved owner's media.
 */

it('refuses to add media for an owner that was never saved', function (): void {
    expect(fn () => MediaLibrary::for(new TestUser)->add(__DIR__.'/../files/pixel.png')->toBucket('gallery'))
        ->toThrow(MediaOwnerNotSaved::class)
        ->and(fn () => (new TestUser)->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('avatar'))
        ->toThrow(MediaOwnerNotSaved::class);

    expect(Media::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and(Storage::disk('cold')->allFiles())->toBe([]);
});

it('refuses to attach, bind, move or copy media to an owner that was never saved', function (string $call): void {
    $media = MediaLibrary::add(__DIR__.'/../files/pixel.png')->toBucket('library');
    $draft = MediaLibrary::draft(__DIR__.'/../files/wide.png')->toBucket();
    $unsaved = new TestUser(['id' => 99]);

    expect(fn () => match ($call) {
        'attach' => MediaLibrary::attach($media, $unsaved, 'gallery'),
        'bindDraft' => MediaLibrary::bindDraft((string) $draft->draft_token, $unsaved, 'gallery'),
        'move' => MediaLibrary::move($media, $unsaved, 'gallery'),
        'copy' => MediaLibrary::copy($media, $unsaved, 'gallery'),
    })->toThrow(MediaOwnerNotSaved::class);

    expect(Media::query()->count())->toBe(2)
        ->and(Media::query()->findOrFail($media->id)->model_type)->toBeNull();
})->with(['attach', 'bindDraft', 'move', 'copy']);

it('scopes an unsaved owner to no media at all', function (): void {
    // A row an earlier version stored for an unsaved owner.
    Media::factory()->create(['model_type' => (new TestUser)->getMorphClass(), 'model_id' => null, 'bucket_name' => 'gallery']);

    expect(Media::query()->forModel(new TestUser)->count())->toBe(0)
        ->and(MediaLibrary::for(new TestUser)->get('gallery'))->toHaveCount(0);
});

it('refuses an unsaved owner under the fake too', function (): void {
    MediaLibrary::fake();

    expect(fn () => MediaLibrary::for(new TestUser)->add(__DIR__.'/../files/pixel.png')->toBucket('gallery'))
        ->toThrow(MediaOwnerNotSaved::class);
});
