<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\SoftDeletingUser;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

/*
 * `moveToDisk()` changes where the files live, nothing else. A trashed (or deleted) owner is
 * invisible to the media's `model` relation, and treating that as "no owner" re-homed the media to
 * global and dropped every variant: the owner link erased with no trace.
 */

it('keeps the owner, bucket and variants when moving the media of a soft-deleted owner to another disk', function (): void {
    $owner = SoftDeletingUser::query()->create(['name' => 'Ada']);
    $media = $owner->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');
    $owner->delete();

    $moved = MediaLibrary::moveToDisk(Media::query()->findOrFail($media->id), 'cold');

    $fresh = Media::query()->findOrFail($media->id);

    expect($fresh->disk)->toBe('cold')
        ->and($fresh->model_type)->toBe($owner->getMorphClass())
        ->and((string) $fresh->model_id)->toBe((string) $owner->id)
        ->and($fresh->bucket_name)->toBe('covers')
        ->and($fresh->hasGeneratedVariant('small'))->toBeTrue()
        ->and($moved->hasGeneratedVariant('small'))->toBeTrue();
    Storage::disk('cold')->assertExists($fresh->getPath('small'));

    $owner->restore();

    expect(MediaLibrary::for($owner)->get('covers')->pluck('id')->all())->toBe([$media->id]);
});

it('keeps the owner columns and variants when moving the media of a deleted owner to another disk', function (): void {
    $owner = TestUser::query()->create(['name' => 'Ada']);
    $media = $owner->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');
    $owner->delete();

    Media::query()->findOrFail($media->id)->moveToDisk('cold');

    $fresh = Media::query()->findOrFail($media->id);

    expect($fresh->disk)->toBe('cold')
        ->and($fresh->model_type)->toBe($owner->getMorphClass())
        ->and((string) $fresh->model_id)->toBe((string) $owner->id)
        ->and($fresh->bucket_name)->toBe('covers')
        ->and($fresh->hasGeneratedVariant('small'))->toBeTrue();
});
