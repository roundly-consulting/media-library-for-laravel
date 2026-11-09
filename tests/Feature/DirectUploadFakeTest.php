<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadActorMismatch;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadNotFound;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadsNotSupported;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Models\MediaUpload;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

/*
 * Under MediaLibrary::fake() direct uploads are recorded, never signed: no ticket row, no disk.
 */

beforeEach(function (): void {
    config()->set('media.direct_uploads.enabled', true);
    $this->fake = MediaLibrary::fake();
});

function fakeUploader(string $name = 'Ada'): TestUser
{
    return TestUser::query()->create(['name' => $name]);
}

it('records an issued upload instead of signing it', function (): void {
    $user = fakeUploader();

    $upload = MediaLibrary::for($user)->upload('a.png', 69)->toMediaBucket('gallery');

    expect($upload->url)->toStartWith('https://fake.test/uploads/')
        ->and(MediaUpload::query()->count())->toBe(0);

    $this->fake->assertUploadIssued('gallery', $user);
    $this->fake->assertUploadIssued();

    expect(fn () => $this->fake->assertNoUploadIssued())->toThrow(ExpectationFailedException::class)
        ->and(fn () => $this->fake->assertUploadIssued('avatar'))->toThrow(ExpectationFailedException::class, 'bucket [avatar]');
});

it('adopts what it issued, once, as an unsaved media', function (): void {
    $user = fakeUploader();
    $upload = app(MediaLibraryManager::class)->for($user)->upload('a.png', 69)->usingName('Pic')->toMediaBucket('gallery');

    $this->fake->assertNothingAdopted();

    $media = MediaLibrary::adoptUpload($upload->token);

    expect($media)->toBeInstanceOf(Media::class)
        ->and($media->exists)->toBeFalse()
        ->and($media->uuid)->toBe($upload->mediaUuid)
        ->and($media->name)->toBe('Pic')
        ->and($media->model_id)->toEqual($user->id)
        ->and(MediaLibrary::adoptUpload($upload->token))->toBe($media);

    $this->fake->assertUploadAdopted('gallery', $user);

    expect(fn () => $this->fake->assertNothingAdopted())->toThrow(ExpectationFailedException::class)
        ->and(fn () => $this->fake->assertUploadAdopted('avatar'))->toThrow(ExpectationFailedException::class);
});

it('refuses what the real manager refuses', function (): void {
    $actor = fakeUploader('Actor');
    $upload = MediaLibrary::upload('a.png', 69)->forActor($actor)->toBucket('library');

    expect(fn () => MediaLibrary::adoptUpload('unknown.token'))->toThrow(DirectUploadNotFound::class)
        ->and(fn () => MediaLibrary::adoptUpload($upload->token, fakeUploader('Mallory')))->toThrow(DirectUploadActorMismatch::class)
        ->and(fn () => MediaLibrary::for(fakeUploader())->upload('big.png', 6 * 1024 * 1024)->toMediaBucket('documents'))->toThrow(FileUnacceptableForBucket::class)
        ->and(fn () => MediaLibrary::for(fakeUploader())->upload('a.txt', 5)->declaringType('text/plain')->toMediaBucket('avatar'))->toThrow(FileUnacceptableForBucket::class)
        ->and(fn () => MediaLibrary::signUploadParts($upload->token, [1]))->toThrow(DirectUploadNotFound::class);

    config()->set('media.direct_uploads.enabled', false);

    expect(fn () => MediaLibrary::upload('a.png', 69)->toBucket('library'))->toThrow(DirectUploadsNotSupported::class);
});

it('signs fake parts, completes, aborts and prunes', function (): void {
    $multipart = MediaLibrary::upload('big.bin', 20 * 1024 * 1024)->multipart()->toBucket('library');
    $other = MediaLibrary::upload('b.png', 69)->toBucket('library');

    $parts = MediaLibrary::signUploadParts($multipart->token, [1, 2]);

    expect($multipart->partCount)->toBe(2)
        ->and($parts[1]->url)->toEndWith('/parts/2')
        ->and(MediaLibrary::completeUpload($multipart->token, [1 => 'a', 2 => 'b'])->uuid)->toBe($multipart->mediaUuid);

    expect(fn () => $this->fake->assertUploadAborted())->toThrow(ExpectationFailedException::class);

    MediaLibrary::abortUpload($other->token);
    MediaLibrary::abortUpload($multipart->token);
    $this->fake->assertUploadAborted();

    expect(fn () => $this->fake->assertUploadsPruned())->toThrow(ExpectationFailedException::class)
        ->and(MediaLibrary::pruneUploads())->toBe(0)
        ->and(fn () => MediaLibrary::adoptUpload($other->token))->toThrow(DirectUploadNotFound::class);

    $this->fake->assertUploadsPruned();

    expect(fn () => $this->fake->assertUploadsNotPruned())->toThrow(ExpectationFailedException::class);
});

it('passes uploads-not-pruned while nothing was pruned', function (): void {
    $this->fake->assertUploadsNotPruned();
    $this->fake->assertNoUploadIssued();
});
