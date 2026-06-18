<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Events\MediaHasBeenMoved;
use RoundlyConsulting\MediaLibrary\Exceptions\DiskDoesNotExist;
use RoundlyConsulting\MediaLibrary\Facades\Media as MediaFacade;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

function moveUser(string $name = 'Jane'): TestUser
{
    return TestUser::query()->create(['name' => $name]);
}

it('moves the original across disks and removes the source', function (): void {
    $user = moveUser();
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    expect($media->disk)->toBe('public');
    Storage::disk('public')->assertExists($media->getPath());

    $media->moveToDisk('cold');

    expect($media->fresh()?->disk)->toBe('cold');
    Storage::disk('cold')->assertExists($media->getPath());
    Storage::disk('public')->assertMissing($media->getPath());
});

it('moves variants alongside the original when they share its disk', function (): void {
    $user = moveUser();
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    expect($media->hasGeneratedVariant('small'))->toBeTrue()
        ->and($media->variants_disk)->toBeNull();

    Storage::disk('public')->assertExists($media->getPath('small'));

    $media->moveToDisk('cold');

    Storage::disk('cold')->assertExists($media->getPath('small'));
    Storage::disk('public')->assertMissing($media->getPath('small'));
});

it('moves only the variant files to another disk', function (): void {
    Bus::fake();

    $user = moveUser();
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('photos');

    expect($media->variants_disk)->toBe('hot');
    Storage::disk('hot')->assertExists($media->getPath('thumb'));

    $media->moveVariantsToDisk('cold');

    expect($media->fresh()?->variants_disk)->toBe('cold');
    Storage::disk('cold')->assertExists($media->getPath('thumb'));
    Storage::disk('hot')->assertMissing($media->getPath('thumb'));
    // The original stays on its disk.
    Storage::disk('public')->assertExists($media->getPath());
});

it('re-homes media from one model to another and to global', function (): void {
    $jane = moveUser('Jane');
    $john = moveUser('John');

    $media = $jane->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    $media->move($john, 'gallery');

    expect($media->model_id)->toBe($john->id)
        ->and($media->model_type)->toBe($john->getMorphClass());

    // model -> global
    $media->move(null, 'brand');

    $fresh = $media->fresh();
    expect($fresh?->model_type)->toBeNull()
        ->and($fresh->model_id)->toBeNull()
        ->and($fresh->bucket_name)->toBe('brand');
});

it('moves global media onto a model', function (): void {
    $logo = MediaFacade::add(__DIR__.'/../files/pixel.png')->toBucket('brand');
    $user = moveUser();

    $logo->move($user, 'gallery');

    expect($logo->model_id)->toBe($user->id)
        ->and($logo->bucket_name)->toBe('gallery');
});

it('fires MediaHasBeenMoved on move', function (): void {
    Event::fake([MediaHasBeenMoved::class]);

    $user = moveUser();
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    $media->moveToDisk('cold');

    Event::assertDispatched(MediaHasBeenMoved::class);
});

it('copies media across disks into a new row with a fresh uuid, preserving the source', function (): void {
    $user = moveUser();
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    $copy = $media->copy($user, 'gallery', 'cold');

    expect($copy->id)->not->toBe($media->id)
        ->and($copy->uuid)->not->toBe($media->uuid)
        ->and($copy->disk)->toBe('cold');

    // Source preserved, copy written.
    Storage::disk('public')->assertExists($media->getPath());
    Storage::disk('cold')->assertExists($copy->getPath());
});

it('copies variants alongside the original', function (): void {
    $user = moveUser();
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    $copy = $media->copy($user, 'covers');

    expect($copy->hasGeneratedVariant('small'))->toBeTrue();
    Storage::disk('public')->assertExists($copy->getPath('small'));
    // Original variant still there.
    Storage::disk('public')->assertExists($media->getPath('small'));
});

it('copies global media onto a model', function (): void {
    $logo = MediaFacade::add(__DIR__.'/../files/pixel.png')->toBucket('brand');
    $user = moveUser();

    $copy = $logo->copy($user, 'gallery');

    expect($copy->model_id)->toBe($user->id);
    Storage::disk('public')->assertExists($logo->getPath());
    Storage::disk('public')->assertExists($copy->getPath());
});

it('validates the target disk exists', function (): void {
    $user = moveUser();
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    expect(fn () => $media->moveToDisk('nope'))
        ->toThrow(DiskDoesNotExist::class);
});

it('moves variants stored on the original disk when re-basing the variants disk on copy', function (): void {
    $user = moveUser();
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    // covers stores variants on the original ('public') disk → variants_disk is null.
    expect($media->variants_disk)->toBeNull();

    $copy = $media->copy($user, 'covers', 'cold');

    // Original moved to 'cold'; variants keep their old disk so variants_disk is re-based.
    expect($copy->disk)->toBe('cold')
        ->and($copy->variants_disk)->toBe('public');

    Storage::disk('public')->assertExists($copy->getPath('small'));
});
