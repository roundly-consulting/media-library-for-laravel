<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Buckets\MediaBucket;
use RoundlyConsulting\MediaLibrary\Exceptions\InvalidVisibility;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Models\Media;

/*
 * Visibility is `public` or `private`, exactly. `Private` was accepted as written and read back as
 * PUBLIC (`isPublic()` is "anything but 'private'") — a typo that publishes a private file.
 */

it('refuses any visibility but public or private on an add', function (string $visibility): void {
    expect(fn () => MediaLibrary::add(__DIR__.'/../files/pixel.png')->withVisibility($visibility))
        ->toThrow(InvalidVisibility::class, $visibility);

    expect(Media::query()->count())->toBe(0);
})->with(['Private', 'PRIVATE', 'privat', 'public ', '']);

it('refuses any visibility but public or private on a bucket', function (string $visibility): void {
    expect(fn () => (new MediaBucket('documents'))->withVisibility($visibility))
        ->toThrow(InvalidVisibility::class, $visibility);
})->with(['Private', 'PUBLIC', 'hidden']);

it('accepts public and private', function (string $visibility): void {
    $media = MediaLibrary::add(__DIR__.'/../files/pixel.png')->withVisibility($visibility)->toBucket('library');

    expect($media->visibility)->toBe($visibility)
        ->and((new MediaBucket('documents'))->withVisibility($visibility)->getVisibility())->toBe($visibility);
})->with(['public', 'private']);
