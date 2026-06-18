<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

function makeMediaUser(): TestUser
{
    return TestUser::query()->create(['name' => 'Jane']);
}

it('exposes the media relationship', function (): void {
    $user = makeMediaUser();
    $user->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaBucket('gallery');

    expect($user->media)->toHaveCount(1);
});

it('reads the first media in a bucket', function (): void {
    $user = makeMediaUser();
    $first = $user->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaBucket('gallery');
    $user->addMedia(UploadedFile::fake()->image('b.jpg'))->toMediaBucket('gallery');

    expect($user->getFirstMedia('gallery')->id)->toBe($first->id);
});

it('reports whether a bucket has media', function (): void {
    $user = makeMediaUser();

    expect($user->hasMedia('gallery'))->toBeFalse();

    $user->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaBucket('gallery');

    expect($user->hasMedia('gallery'))->toBeTrue();
});

it('returns the first media url', function (): void {
    $user = makeMediaUser();
    $user->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaBucket('gallery');

    expect($user->getFirstMediaUrl('gallery'))->toContain('.jpg');
});

it('returns an empty string when a bucket is empty and has no fallback', function (): void {
    $user = makeMediaUser();

    expect($user->getFirstMediaUrl('gallery'))->toBe('');
});

it('returns the bucket fallback url when empty', function (): void {
    $user = makeMediaUser();

    expect($user->getFirstMediaUrl('avatar'))->toBe('https://example.com/fallback-avatar.png');
});

it('clears a media bucket', function (): void {
    $user = makeMediaUser();
    $user->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaBucket('gallery');

    $user->clearMediaBucket('gallery');

    expect($user->getMedia('gallery'))->toHaveCount(0);
});

it('adds multiple media from request keys', function (): void {
    $user = makeMediaUser();

    $request = Request::create('/', 'POST', [], [], [
        'one' => UploadedFile::fake()->image('one.jpg'),
        'two' => UploadedFile::fake()->image('two.jpg'),
    ]);
    app()->instance('request', $request);

    $adders = $user->addMultipleMediaFromRequest(['one', 'two', 'missing']);

    expect($adders)->toHaveCount(2);
    foreach ($adders as $adder) {
        $adder->toMediaBucket('gallery');
    }
    expect($user->getMedia('gallery'))->toHaveCount(2);
});

it('adds media from a request key', function (): void {
    $user = makeMediaUser();

    $request = Request::create('/', 'POST', [], [], [
        'avatar' => UploadedFile::fake()->image('a.jpg'),
    ]);
    app()->instance('request', $request);

    $media = $user->addMediaFromRequest('avatar')->toMediaBucket('gallery');

    expect($media->exists)->toBeTrue();
});

it('resolves a declared bucket and returns null for an unknown one', function (): void {
    $user = makeMediaUser();

    expect($user->resolveMediaBucket('avatar'))->not->toBeNull()
        ->and($user->resolveMediaBucket('unknown'))->toBeNull();
});
