<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Facades\Media as MediaFacade;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\CustomMedia;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * The `media.media_model` seam. Every read, write and delete in the package must go through the
 * configured model — a seam honoured in only some call sites is worse than none, because the
 * observer that cleans up stored files hangs off the configured model's events.
 *
 * The model is configured before the app boots (see the TestCase), so the observer is registered
 * on the host's model alone — that is what makes the file-cleanup assertions below able to fail.
 */
it('resolves the configured model', function (): void {
    expect(MediaModel::class())->toBe(CustomMedia::class)
        ->and(MediaModel::new())->toBeInstanceOf(CustomMedia::class);
});

it('persists new media as the configured model', function (): void {
    $user = TestUser::query()->create(['name' => 'Ada']);

    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    expect($media)->toBeInstanceOf(CustomMedia::class);
});

it('reads the media relation through the configured model', function (): void {
    $user = TestUser::query()->create(['name' => 'Ada']);
    $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    expect($user->getFirstMedia('gallery'))->toBeInstanceOf(CustomMedia::class)
        ->and($user->media()->getRelated())->toBeInstanceOf(CustomMedia::class);
});

it('serves global media through the configured model', function (): void {
    MediaFacade::add(__DIR__.'/../files/pixel.png')->toBucket('brand');

    expect(MediaFacade::bucket('brand')->first())->toBeInstanceOf(CustomMedia::class);
});

// The bug this pins: `AddMediaAction::clearBucket()` queried the PACKAGED model, so the rows it
// force-deleted never fired the observer (which is registered on the CONFIGURED model) — leaving
// the replaced file orphaned on disk forever.
it('deletes the replaced file when a single-file bucket is refilled', function (): void {
    $user = TestUser::query()->create(['name' => 'Ada']);

    $first = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('avatar');
    $firstPath = $first->getPath();

    expect(Storage::disk('cold')->exists($firstPath))->toBeTrue();

    // Different bytes, so dedup cannot keep the original alive for a legitimate reason.
    $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('avatar');

    expect(CustomMedia::query()->count())->toBe(1)
        ->and(Storage::disk('cold')->exists($firstPath))->toBeFalse();
});

it('numbers the order column from the configured model', function (): void {
    $user = TestUser::query()->create(['name' => 'Ada']);

    $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $second = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');

    expect($second->order_column)->toBe(2);
});

it('attaches and copies through the configured model', function (): void {
    $user = TestUser::query()->create(['name' => 'Ada']);
    $global = MediaFacade::add(__DIR__.'/../files/pixel.png')->toBucket('brand');

    $attached = $user->attachMedia($global, 'gallery');
    $copy = $attached->copy($user, 'gallery');

    expect($attached)->toBeInstanceOf(CustomMedia::class)
        ->and($copy)->toBeInstanceOf(CustomMedia::class)
        ->and($copy->order_column)->toBe(2);
});

it('falls back to the packaged model for an eloquent model that is not a media', function (): void {
    config()->set('media.media_model', TestUser::class);

    expect(MediaModel::class())->toBe(Media::class);
});

it('throws when the configured model is not a model class', function (): void {
    config()->set('media.media_model', 'Not\\A\\Class');

    expect(fn (): string => MediaModel::class())->toThrow(InvalidConfigurationException::class);
});
