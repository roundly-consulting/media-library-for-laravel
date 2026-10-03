<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
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
    MediaLibrary::add(__DIR__.'/../files/pixel.png')->toBucket('brand');

    expect(MediaLibrary::bucket('brand')->first())->toBeInstanceOf(CustomMedia::class);
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
    $global = MediaLibrary::add(__DIR__.'/../files/pixel.png')->toBucket('brand');

    $attached = $user->attachMedia($global, 'gallery');
    $copy = $attached->copy($user, 'gallery');

    expect($attached)->toBeInstanceOf(CustomMedia::class)
        ->and($copy)->toBeInstanceOf(CustomMedia::class)
        ->and($copy->order_column)->toBe(2);
});

it('refuses a foreign model instead of falling back to the packaged one', function (): void {
    // The toolkit refuses any class that is not the packaged model or a subclass of it.
    config()->set('media.media_model', TestUser::class);

    expect(fn (): string => MediaModel::class())->toThrow(
        InvalidConfigurationException::class,
        'Configuration value [media.media_model] must be a class-string of ['.Media::class.'], ['.TestUser::class.'] given.',
    );
});

it('throws when the configured model is not a model class', function (): void {
    config()->set('media.media_model', 'Not\\A\\Class');

    expect(fn (): string => MediaModel::class())->toThrow(InvalidConfigurationException::class);
});

/**
 * The model-swap proof (S), replacing the `instanceof` half of the checks above.
 *
 * `instanceof` passes for a row created as the packaged class — which never fires the host's
 * model events (permissions #31). In media that IS the bug: the observer that cleans files off
 * disk is registered on the configured model, so a row created as the packaged Media leaves its
 * file orphaned forever (#28) while every `instanceof` assertion stays green.
 *
 * `toHonourModelSwap` fails fast if the before-boot swap is missing, then asserts every returned
 * model's CONCRETE class, and — because CustomMedia uses `CountsCreations` — that a `created`
 * event actually landed on the host's class. That is the only proof the row was made *as* the
 * host's model rather than merely being castable to it.
 */
it('honours a host media model through every add and read flow', function (): void {
    expect('media.media_model')->toHonourModelSwap(CustomMedia::class, function (): array {
        $user = TestUser::query()->create(['name' => 'Ada']);

        // The real flows a host uses: an owned add, a global add, and the reads back.
        $owned = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
        $global = MediaLibrary::add(__DIR__.'/../files/wide.png')->toBucket('brand');

        return [
            $owned,
            $global,
            $user->getFirstMedia('gallery'),
            ...MediaLibrary::bucket('brand')->get()->all(),
        ];
    });
});
