<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\DirectUpload;
use RoundlyConsulting\MediaLibrary\Enums\DirectUploadStatus;
use RoundlyConsulting\MediaLibrary\Events\DirectUploadHasBeenAdopted;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadNotFound;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadsNotSupported;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadTargetMismatch;
use RoundlyConsulting\MediaLibrary\Exceptions\MediaLibraryException;
use RoundlyConsulting\MediaLibrary\Exceptions\TooManyPendingDirectUploads;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Models\MediaUpload;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\EdgeCaseUser;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

/*
 * A ticket is issued for one owner and bucket. A route that adopts by token can say which owner
 * and bucket it expects, and a token issued elsewhere is refused without being spent.
 */

beforeEach(function (): void {
    config()->set('media.direct_uploads.enabled', true);

    Storage::disk('public')->buildTemporaryUploadUrlsUsing(
        fn (string $path, DateTimeInterface $expiry, array $options): array => ['url' => "https://public.test/{$path}", 'headers' => []],
    );
});

function targetUser(string $name = 'Ada'): TestUser
{
    return TestUser::query()->create(['name' => $name]);
}

/** A gallery ticket for `$user`, with the bytes already in storage. */
function sentGalleryUpload(TestUser $user): DirectUpload
{
    $upload = MediaLibrary::for($user)->upload('sunrise.png', 9274)->toMediaBucket('gallery');
    Storage::disk('public')->put($upload->key, (string) file_get_contents(__DIR__.'/../files/sunrise.png'));

    return $upload;
}

function targetTicket(DirectUpload $upload): MediaUpload
{
    return MediaUpload::query()->findOrFail(strtolower(explode('.', $upload->token)[0]));
}

it('refuses a ticket issued for another owner without spending it', function (): void {
    Event::fake([DirectUploadHasBeenAdopted::class]);
    $owner = targetUser();
    $upload = sentGalleryUpload($owner);

    expect(fn () => MediaLibrary::adoptUpload($upload->token, expectedOwner: targetUser('Mallory')))
        ->toThrow(DirectUploadTargetMismatch::class, 'another owner')
        ->and(targetTicket($upload)->status)->toBe(DirectUploadStatus::Pending)
        ->and(targetTicket($upload)->claimed_at)->toBeNull()
        ->and(Media::query()->count())->toBe(0)
        ->and(Storage::disk('public')->exists($upload->key))->toBeTrue();

    Event::assertNotDispatched(DirectUploadHasBeenAdopted::class);

    $media = MediaLibrary::adoptUpload($upload->token, expectedOwner: $owner, expectedBucket: 'gallery');

    expect($media->exists)->toBeTrue()
        ->and($media->model_id)->toEqual($owner->id)
        ->and(targetTicket($upload)->status)->toBe(DirectUploadStatus::Adopted);
});

it('compares the owner by morph type as well as key', function (): void {
    $owner = targetUser();
    $upload = sentGalleryUpload($owner);
    $sameKeyOtherType = EdgeCaseUser::query()->findOrFail($owner->id);

    expect(fn () => MediaLibrary::adoptUpload($upload->token, expectedOwner: $sameKeyOtherType))
        ->toThrow(DirectUploadTargetMismatch::class)
        ->and(fn () => MediaLibrary::adoptUpload($upload->token, expectedOwner: new TestUser))
        ->toThrow(DirectUploadTargetMismatch::class)
        ->and(targetTicket($upload)->status)->toBe(DirectUploadStatus::Pending);
});

it('refuses a ticket issued for another bucket without spending it', function (): void {
    $owner = targetUser();
    $upload = sentGalleryUpload($owner);

    try {
        MediaLibrary::adoptUpload($upload->token, expectedOwner: $owner, expectedBucket: 'avatar');
        $this->fail('Expected a target mismatch.');
    } catch (DirectUploadTargetMismatch $refused) {
        expect($refused)->toBeInstanceOf(MediaLibraryException::class)
            ->and($refused->mismatched)->toBe(DirectUploadTargetMismatch::BUCKET)
            ->and($refused->getMessage())->toBe('This direct upload was issued for another bucket.');
    }

    expect(targetTicket($upload)->status)->toBe(DirectUploadStatus::Pending)
        ->and(MediaLibrary::adoptUpload($upload->token, expectedBucket: 'gallery')->bucket_name)->toBe('gallery');
});

it('never names the ticket\'s owner or bucket in the refusal', function (): void {
    $owner = targetUser();
    $upload = sentGalleryUpload($owner);

    try {
        MediaLibrary::adoptUpload($upload->token, expectedOwner: targetUser('Mallory'));
        $this->fail('Expected a target mismatch.');
    } catch (DirectUploadTargetMismatch $refused) {
        expect($refused->mismatched)->toBe(DirectUploadTargetMismatch::OWNER)
            ->and($refused->getMessage())->not->toContain((string) $owner->id)
            ->and($refused->getMessage())->not->toContain('gallery')
            ->and($refused->getMessage())->not->toContain($owner->getMorphClass());
    }
});

it('adopts from any route when no target is expected, as before', function (): void {
    $upload = sentGalleryUpload(targetUser());

    expect(MediaLibrary::adoptUpload($upload->token)->exists)->toBeTrue();
});

it('refuses a global or draft ticket when an owner is expected', function (): void {
    $owner = targetUser();
    $global = MediaLibrary::upload('a.png', 5)->toBucket('library');
    $draft = MediaLibrary::for($owner)->upload('b.png', 5)->asDraft()->toMediaBucket('gallery');

    expect(fn () => MediaLibrary::adoptUpload($global->token, expectedOwner: $owner))->toThrow(DirectUploadTargetMismatch::class)
        ->and(fn () => MediaLibrary::adoptUpload($draft->token, expectedOwner: $owner))->toThrow(DirectUploadTargetMismatch::class)
        ->and(fn () => MediaLibrary::adoptUpload($global->token, expectedBucket: 'gallery'))->toThrow(DirectUploadTargetMismatch::class)
        ->and(targetTicket($global)->status)->toBe(DirectUploadStatus::Pending)
        ->and(targetTicket($draft)->status)->toBe(DirectUploadStatus::Pending);
});

it('does not hand out an adopted ticket\'s media to another owner', function (): void {
    $owner = targetUser();
    $upload = sentGalleryUpload($owner);
    $media = MediaLibrary::adoptUpload($upload->token, expectedOwner: $owner);

    expect(fn () => MediaLibrary::adoptUpload($upload->token, expectedOwner: targetUser('Mallory')))->toThrow(DirectUploadTargetMismatch::class)
        ->and(MediaLibrary::adoptUpload($upload->token, expectedOwner: $owner)->is($media))->toBeTrue();
});

it('checks the target on completeUpload before touching the upload', function (): void {
    $owner = targetUser();
    $upload = sentGalleryUpload($owner);

    // A put ticket cannot be completed: the target check runs first, the multipart step after it.
    expect(fn () => MediaLibrary::completeUpload($upload->token, [1 => 'etag'], expectedOwner: targetUser('Mallory')))
        ->toThrow(DirectUploadTargetMismatch::class)
        ->and(fn () => app(MediaLibraryManager::class)->completeUpload($upload->token, [1 => 'etag'], expectedOwner: $owner, expectedBucket: 'gallery'))
        ->toThrow(DirectUploadNotFound::class)
        ->and(targetTicket($upload)->status)->toBe(DirectUploadStatus::Pending);
});

it('throws a dedicated exception, still a DirectUploadsNotSupported, for too many pending uploads', function (): void {
    config()->set('media.direct_uploads.max_pending_per_owner', 1);
    $owner = targetUser();
    $actor = targetUser('Actor');

    MediaLibrary::for($owner)->upload('a.png', 5)->toMediaBucket('gallery');
    MediaLibrary::upload('b.png', 5)->forActor($actor)->toBucket('library');

    try {
        MediaLibrary::for($owner)->upload('c.png', 5)->toMediaBucket('gallery');
        $this->fail('Expected too many pending uploads.');
    } catch (DirectUploadsNotSupported $refused) {
        expect($refused)->toBeInstanceOf(TooManyPendingDirectUploads::class)
            ->and($refused->getMessage())->toBe('There are already 1 pending direct uploads for this owner.');

        assert($refused instanceof TooManyPendingDirectUploads);
        expect($refused->limit)->toBe(1)
            ->and($refused->holder?->is($owner))->toBeTrue();
    }

    expect(fn () => MediaLibrary::upload('d.png', 5)->forActor($actor)->toBucket('library'))
        ->toThrow(function (TooManyPendingDirectUploads $refused) use ($actor): void {
            expect($refused->limit)->toBe(1)->and($refused->holder?->is($actor))->toBeTrue();
        });
});

it('keeps the old factory working', function (): void {
    $refused = DirectUploadsNotSupported::tooManyPending(3);

    expect($refused)->toBeInstanceOf(TooManyPendingDirectUploads::class)
        ->and($refused)->toBeInstanceOf(DirectUploadsNotSupported::class)
        ->and($refused->getMessage())->toBe('There are already 3 pending direct uploads for this owner.');
});
