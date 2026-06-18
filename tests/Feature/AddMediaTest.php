<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Events\MediaHasBeenAdded;
use RoundlyConsulting\MediaLibrary\Exceptions\FileDoesNotExist;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\MediaLibrary\Exceptions\InvalidBase64Data;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

function makeUser(): TestUser
{
    return TestUser::query()->create(['name' => 'Jane']);
}

it('adds media from an uploaded file', function (): void {
    $user = makeUser();
    $file = UploadedFile::fake()->image('photo.jpg');

    $media = $user->addMedia($file)->toMediaBucket('gallery');

    expect($media)->toBeInstanceOf(Media::class)
        ->and($media->bucket_name)->toBe('gallery')
        ->and($media->disk)->toBe('public')
        ->and($media->model_id)->toBe($user->id)
        ->and($media->model_type)->toBe($user->getMorphClass());

    Storage::disk('public')->assertExists($media->getPath());
});

it('adds media from a local path', function (): void {
    $user = makeUser();

    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    expect($media->file_name)->toBe('pixel.png')
        ->and($media->extension)->toBe('png');

    Storage::disk('public')->assertExists($media->getPath());
});

it('adds media from a url', function (): void {
    Http::fake([
        '*' => Http::response('binary-bytes', 200, ['Content-Type' => 'image/png']),
    ]);

    $user = makeUser();
    $media = $user->addMediaFromUrl('https://example.com/poster.png')->toMediaBucket('gallery');

    expect($media->mime_type)->toBe('image/png');
    Storage::disk('public')->assertExists($media->getPath());
});

it('throws when a remote url cannot be fetched', function (): void {
    Http::fake(['*' => Http::response('', 404)]);

    $user = makeUser();

    expect(fn () => $user->addMediaFromUrl('https://example.com/missing.png')->toMediaBucket('gallery'))
        ->toThrow(FileDoesNotExist::class);
});

it('adds media from a disk file', function (): void {
    Storage::disk('public')->put('incoming/doc.txt', 'hello world');

    $user = makeUser();
    $media = $user->addMediaFromDisk('incoming/doc.txt')->toMediaBucket('gallery');

    expect($media->file_name)->toBe('doc.txt');
    Storage::disk('public')->assertExists($media->getPath());
});

it('throws when a disk file is missing', function (): void {
    $user = makeUser();

    expect(fn () => $user->addMediaFromDisk('nope/missing.txt')->toMediaBucket('gallery'))
        ->toThrow(FileDoesNotExist::class);
});

it('adds media from a raw string', function (): void {
    $user = makeUser();
    $media = $user->addMediaFromString('raw content')->usingFileName('note.txt')->toMediaBucket('gallery');

    expect((int) $media->size)->toBe(strlen('raw content'));
    Storage::disk('public')->assertExists($media->getPath());
});

it('adds media from base64', function (): void {
    $user = makeUser();
    $base64 = 'data:text/plain;base64,'.base64_encode('decoded');

    $media = $user->addMediaFromBase64($base64)->usingFileName('b.txt')->toMediaBucket('gallery');

    expect(Storage::disk('public')->get($media->getPath()))->toBe('decoded');
});

it('rejects invalid base64', function (): void {
    $user = makeUser();

    expect(fn () => $user->addMediaFromBase64('!!!not base64!!!')->toMediaBucket('gallery'))
        ->toThrow(InvalidBase64Data::class);
});

it('adds media from a stream', function (): void {
    $user = makeUser();
    $stream = fopen('php://temp', 'r+');
    fwrite($stream, 'streamed');
    rewind($stream);

    $media = $user->addMediaFromStream($stream)->usingFileName('s.txt')->toMediaBucket('gallery');
    fclose($stream);

    expect(Storage::disk('public')->get($media->getPath()))->toBe('streamed');
});

it('propagates name and custom properties', function (): void {
    $user = makeUser();
    $media = $user->addMedia(UploadedFile::fake()->image('x.jpg'))
        ->usingName('Profile photo')
        ->usingFileName('avatar-stored.jpg')
        ->withCustomProperties(['alt' => 'Jane'])
        ->withProperty('source', 'signup')
        ->toMediaBucket('gallery');

    expect($media->name)->toBe('Profile photo')
        ->and($media->file_name)->toBe('avatar-stored.jpg')
        ->and($media->getCustomProperty('alt'))->toBe('Jane')
        ->and($media->getCustomProperty('source'))->toBe('signup');
});

it('rejects a mime type the bucket does not accept', function (): void {
    $user = makeUser();

    expect(fn () => $user->addMediaFromString('text')->usingFileName('a.txt')->toMediaBucket('avatar'))
        ->toThrow(FileUnacceptableForBucket::class);
});

it('replaces previous media in a single file bucket', function (): void {
    $user = makeUser();

    $first = $user->addMedia(UploadedFile::fake()->image('one.jpg'))->toMediaBucket('avatar');
    $second = $user->addMedia(UploadedFile::fake()->image('two.jpg'))->toMediaBucket('avatar');

    expect($user->getMedia('avatar'))->toHaveCount(1)
        ->and($user->getFirstMedia('avatar')->id)->toBe($second->id);

    Storage::disk('cold')->assertMissing($first->getPath());
});

it('keeps multiple files in a non single-file bucket', function (): void {
    $user = makeUser();

    $user->addMedia(UploadedFile::fake()->image('one.jpg'))->toMediaBucket('gallery');
    $user->addMedia(UploadedFile::fake()->image('two.jpg'))->toMediaBucket('gallery');

    expect($user->getMedia('gallery'))->toHaveCount(2);
});

it('orders media within a bucket', function (): void {
    $user = makeUser();

    $a = $user->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaBucket('gallery');
    $b = $user->addMedia(UploadedFile::fake()->image('b.jpg'))->toMediaBucket('gallery');

    expect($a->order_column)->toBe(1)
        ->and($b->order_column)->toBe(2);
});

it('fires an event when media is added', function (): void {
    Event::fake([MediaHasBeenAdded::class]);

    $user = makeUser();
    $user->addMedia(UploadedFile::fake()->image('a.jpg'))->toMediaBucket('gallery');

    Event::assertDispatched(MediaHasBeenAdded::class);
});

it('throws when adding a missing uploaded file path', function (): void {
    $user = makeUser();

    expect(fn () => $user->addMedia('/does/not/exist.png')->toMediaBucket('gallery'))
        ->toThrow(FileDoesNotExist::class);
});
