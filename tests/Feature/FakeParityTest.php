<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Exceptions\DiskDoesNotExist;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\EdgeCaseUser;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

/*
 * The fake differs from the real manager in its side effects only: what the real call refuses,
 * the fake refuses, and what it returns carries the storage the real call would have used. A host
 * test of an error path must not pass against the fake and fail against the real thing.
 */

function textMedia(): Media
{
    return Media::factory()->create(['mime_type' => 'text/plain', 'extension' => 'txt', 'file_name' => 'note.txt']);
}

beforeEach(fn () => MediaLibrary::fake());

it('refuses to attach a file the target bucket does not accept', function (): void {
    $user = TestUser::query()->create(['name' => 'Ada']);

    expect(fn () => MediaLibrary::attach(textMedia(), $user, 'avatar'))->toThrow(FileUnacceptableForBucket::class);
});

it('refuses to move or copy a file into a bucket that does not accept it', function (): void {
    $user = TestUser::query()->create(['name' => 'Ada']);

    expect(fn () => MediaLibrary::move(textMedia(), $user, 'avatar'))->toThrow(FileUnacceptableForBucket::class)
        ->and(fn () => MediaLibrary::copy(textMedia(), $user, 'avatar'))->toThrow(FileUnacceptableForBucket::class);
});

it('refuses to replace a file with one its bucket does not accept', function (): void {
    $user = TestUser::query()->create(['name' => 'Ada']);
    $media = Media::factory()->create([
        'model_type' => $user->getMorphClass(),
        'model_id' => (string) $user->id,
        'bucket_name' => 'avatar',
    ]);

    expect(fn () => MediaLibrary::replace($media, __DIR__.'/../files/note.txt'))->toThrow(FileUnacceptableForBucket::class);
});

it('refuses a disk that is not configured, like the real manager', function (string $call): void {
    $media = Media::factory()->create();

    expect(fn () => match ($call) {
        'move' => MediaLibrary::move($media, null, 'library', 'nowhere'),
        'moveToDisk' => MediaLibrary::moveToDisk($media, 'nowhere'),
        'moveVariantsToDisk' => MediaLibrary::moveVariantsToDisk($media, 'nowhere'),
        'copy' => MediaLibrary::copy($media, null, 'library', 'nowhere'),
        'add' => MediaLibrary::add(__DIR__.'/../files/pixel.png')->useDisk('nowhere')->toBucket('library'),
    })->toThrow(DiskDoesNotExist::class);
})->with(['move', 'moveToDisk', 'moveVariantsToDisk', 'copy', 'add']);

it('binds a draft onto the disk and visibility of its bucket', function (): void {
    $owner = EdgeCaseUser::query()->create(['name' => 'Ada']);
    $draft = MediaLibrary::draft(__DIR__.'/../files/pixel.png')->toBucket();

    $bound = MediaLibrary::for($owner)->bindDraft((string) $draft->draft_token, 'vaulted');

    expect($bound->disk)->toBe('cold')
        ->and($bound->visibility)->toBe('private');
});

it('records the variants disk an add would use', function (): void {
    $user = TestUser::query()->create(['name' => 'Ada']);

    $media = MediaLibrary::for($user)->add(__DIR__.'/../files/pixel.png')->toBucket('photos');

    expect($media->disk)->toBe('public')
        ->and($media->variants_disk)->toBe('hot');
});
