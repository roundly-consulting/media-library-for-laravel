<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Actions\DeleteMediaAction;
use RoundlyConsulting\MediaLibrary\Events\MediaHasBeenDeleted;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

function deleteUser(): TestUser
{
    return TestUser::query()->create(['name' => 'Jane']);
}

it('removes the row and its files', function (): void {
    $user = deleteUser();
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    Storage::disk('public')->assertExists($media->getPath());
    Storage::disk('public')->assertExists($media->getPath('small'));

    app(DeleteMediaAction::class)->execute($media);

    expect(Media::withTrashed()->find($media->id))->toBeNull();
    Storage::disk('public')->assertMissing($media->getPath());
    Storage::disk('public')->assertMissing($media->getPath('small'));
});

it('removes variant files stored on a separate disk', function (): void {
    $user = deleteUser();
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('photos');

    Storage::disk('hot')->assertExists($media->getPath('thumb'));

    $media->deleteWithFiles();

    Storage::disk('public')->assertMissing($media->getPath());
    Storage::disk('hot')->assertMissing($media->getPath('thumb'));
});

it('fires MediaHasBeenDeleted', function (): void {
    Event::fake([MediaHasBeenDeleted::class]);

    $user = deleteUser();
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    app(DeleteMediaAction::class)->execute($media);

    Event::assertDispatched(MediaHasBeenDeleted::class);
});

it('keeps files on a soft delete', function (): void {
    $user = deleteUser();
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    $media->delete();

    expect($media->trashed())->toBeTrue();
    Storage::disk('public')->assertExists($media->getPath());
});
