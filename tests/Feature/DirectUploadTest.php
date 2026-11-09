<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Contracts\PathGenerator;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\DirectUpload;
use RoundlyConsulting\MediaLibrary\Enums\DirectUploadMethod;
use RoundlyConsulting\MediaLibrary\Enums\DirectUploadStatus;
use RoundlyConsulting\MediaLibrary\Events\DirectUploadHasBeenAdopted;
use RoundlyConsulting\MediaLibrary\Events\DirectUploadHasBeenRejected;
use RoundlyConsulting\MediaLibrary\Events\MediaHasBeenAdded;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadActorMismatch;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadExpired;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadIncomplete;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadNotFound;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadsNotSupported;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\MediaLibrary\Exceptions\InvalidVisibility;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Jobs\GenerateVariantsJob;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Models\MediaUpload;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TenantPathGenerator;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

/*
 * Direct uploads on a plain (non-S3) disk: the browser PUTs to a URL the disk signs, the package
 * adopts the stored file by moving it — never by writing a second copy.
 */

beforeEach(function (): void {
    config()->set('media.direct_uploads.enabled', true);

    foreach (['public', 'cold', 'hot'] as $disk) {
        Storage::disk($disk)->buildTemporaryUploadUrlsUsing(
            fn (string $path, DateTimeInterface $expiry, array $options): array => ['url' => "https://{$disk}.test/{$path}", 'headers' => ['Host' => "{$disk}.test", 'Content-Type' => 'application/octet-stream']],
        );
    }
});

function uploader(string $name = 'Ada'): TestUser
{
    return TestUser::query()->create(['name' => $name]);
}

/** What the browser does with a `put` ticket: send the bytes to the key. */
function sendBytes(DirectUpload $upload, string $bytes, string $disk = 'public'): void
{
    Storage::disk($disk)->put($upload->key, $bytes);
}

function uploadFixture(string $file): string
{
    return (string) file_get_contents(__DIR__.'/../files/'.$file);
}

function ticketOf(DirectUpload $upload): MediaUpload
{
    return MediaUpload::query()->findOrFail(strtolower(explode('.', $upload->token)[0]));
}

it('refuses everything while direct uploads are off', function (): void {
    config()->set('media.direct_uploads.enabled', false);

    expect(fn () => MediaLibrary::for(uploader())->upload('a.png', 69)->toMediaBucket('gallery'))->toThrow(DirectUploadsNotSupported::class)
        ->and(fn () => MediaLibrary::adoptUpload('01J0000000000000000000000A.secret'))->toThrow(DirectUploadsNotSupported::class)
        ->and(fn () => MediaLibrary::adoptFromDisk('_uploads/a.png'))->toThrow(DirectUploadsNotSupported::class);
});

it('issues a ticket for a server-minted staging key', function (): void {
    $user = uploader();

    $upload = MediaLibrary::for($user)->upload('../../etc/Holiday photo.png', 69)
        ->declaringType('image/png')
        ->usingName('Holiday')
        ->withProperty('alt', 'Sea')
        ->toMediaBucket('gallery');

    $ticket = ticketOf($upload);

    expect($upload->method)->toBe(DirectUploadMethod::Put)
        ->and($upload->key)->toMatch('#^_uploads/[0-9a-z]{26}$#')
        ->and($upload->url)->toBe('https://public.test/'.$upload->key)
        ->and($upload->headers)->toBe(['Content-Type' => 'application/octet-stream'])
        ->and($upload->maxSize)->toBe(69)
        ->and($upload->sizeEnforcedBySignature)->toBeFalse()
        ->and($upload->token)->toMatch('#^[0-9a-z]{26}\.[A-Za-z0-9]{48}$#')
        ->and($ticket->status)->toBe(DirectUploadStatus::Pending)
        ->and($ticket->token_hash)->not->toContain(explode('.', $upload->token)[1])
        ->and($ticket->media_uuid)->toBe($upload->mediaUuid)
        ->and($ticket->model_id)->toBe((string) $user->id)
        ->and($ticket->in_place)->toBeFalse()
        ->and($ticket->toArray())->not->toHaveKey('token_hash');
});

it('refuses a declared size over the cap, a declared type the bucket refuses, and a disk that cannot sign', function (): void {
    $user = uploader();

    expect(fn () => MediaLibrary::for($user)->upload('big.png', 6 * 1024 * 1024)->toMediaBucket('documents'))
        ->toThrow(FileUnacceptableForBucket::class)
        ->and(fn () => MediaLibrary::for($user)->upload('a.txt', 5)->declaringType('text/plain')->toMediaBucket('avatar'))
        ->toThrow(FileUnacceptableForBucket::class)
        ->and(fn () => MediaLibrary::for($user)->upload('a.png', 5)->toMediaBucket('vault'))
        ->toThrow(DirectUploadsNotSupported::class, 'sign upload URLs')
        ->and(fn () => MediaLibrary::for($user)->upload('a.png', 5)->multipart()->toMediaBucket('gallery'))
        ->toThrow(DirectUploadsNotSupported::class, 'S3 only')
        ->and(fn () => MediaLibrary::for($user)->upload('a.png', 0))->toThrow(InvalidArgumentException::class)
        ->and(MediaUpload::query()->count())->toBe(0);
});

it('caps pending tickets per owner and per actor', function (): void {
    config()->set('media.direct_uploads.max_pending_per_owner', 2);
    $user = uploader();
    $actor = uploader('Actor');

    MediaLibrary::for($user)->upload('a.png', 5)->toMediaBucket('gallery');
    MediaLibrary::for($user)->upload('b.png', 5)->toMediaBucket('gallery');
    MediaLibrary::upload('c.png', 5)->forActor($actor)->toBucket('library');
    MediaLibrary::upload('d.png', 5)->forActor($actor)->toBucket('library');

    expect(fn () => MediaLibrary::for($user)->upload('c.png', 5)->toMediaBucket('gallery'))->toThrow(DirectUploadsNotSupported::class, 'pending')
        ->and(fn () => MediaLibrary::upload('e.png', 5)->forActor($actor)->toBucket('library'))->toThrow(DirectUploadsNotSupported::class, 'pending')
        ->and(MediaLibrary::upload('f.png', 5)->toBucket('library'))->toBeInstanceOf(DirectUpload::class);
});

it('adopts the stored file by moving it, inspected where it lies', function (): void {
    Event::fake([DirectUploadHasBeenAdopted::class, MediaHasBeenAdded::class]);
    $user = uploader();
    $upload = MediaLibrary::for($user)->upload('sunrise.png', 9274)->usingName('Sunrise')->withProperty('alt', 'Sea')->toMediaBucket('gallery');
    sendBytes($upload, uploadFixture('sunrise.png'));

    $media = MediaLibrary::adoptUpload($upload->token);

    expect($media->exists)->toBeTrue()
        ->and($media->uuid)->toBe($upload->mediaUuid)
        ->and($media->model_id)->toEqual($user->id)
        ->and($media->bucket_name)->toBe('gallery')
        ->and($media->name)->toBe('Sunrise')
        ->and($media->file_name)->toBe('sunrise.png')
        ->and($media->mime_type)->toBe('image/png')
        ->and($media->size)->toBe(9274)
        ->and([$media->width, $media->height])->toBe([75, 100])
        ->and($media->checksum)->toBe(hash_file('sha256', __DIR__.'/../files/sunrise.png'))
        ->and($media->custom_properties)->toBe(['alt' => 'Sea'])
        ->and($media->getPath())->toBe($media->uuid.'/sunrise.png')
        ->and($media->placeholders)->not->toBeEmpty()
        ->and(Storage::disk('public')->get($media->getPath()))->toBe(uploadFixture('sunrise.png'))
        ->and(Storage::disk('public')->exists($upload->key))->toBeFalse()
        ->and(ticketOf($upload)->status)->toBe(DirectUploadStatus::Adopted)
        ->and(ticketOf($upload)->media_id)->toBe($media->id);

    Event::assertDispatched(MediaHasBeenAdded::class);
    Event::assertDispatched(DirectUploadHasBeenAdopted::class, fn (DirectUploadHasBeenAdopted $event): bool => $event->media->is($media) && ! $event->deduplicated);
});

it('returns the same media when a ticket is adopted again', function (): void {
    $upload = MediaLibrary::for(uploader())->upload('a.png', 69)->toMediaBucket('gallery');
    sendBytes($upload, uploadFixture('pixel.png'));

    $first = MediaLibrary::adoptUpload($upload->token);
    $second = MediaLibrary::adoptUpload($upload->token);

    expect($second->is($first))->toBeTrue()
        ->and(Media::query()->count())->toBe(1);
});

it('keeps the ticket usable while the file is not uploaded yet', function (): void {
    $upload = MediaLibrary::for(uploader())->upload('a.png', 69)->toMediaBucket('gallery');

    expect(fn () => MediaLibrary::adoptUpload($upload->token))->toThrow(DirectUploadIncomplete::class)
        ->and(ticketOf($upload)->status)->toBe(DirectUploadStatus::Pending);

    sendBytes($upload, uploadFixture('pixel.png'));

    expect(MediaLibrary::adoptUpload($upload->token)->exists)->toBeTrue();
});

it('rejects a file its bucket refuses, deletes it, and refuses the ticket after', function (string $file, string $bucket, int $declared): void {
    Event::fake([DirectUploadHasBeenRejected::class]);
    $upload = MediaLibrary::for(uploader())->upload('photo.png', $declared)->declaringType('image/png')->toMediaBucket($bucket);
    sendBytes($upload, $file === 'polyglot' ? '<!DOCTYPE html><html><script>alert(1)</script></html>' : uploadFixture($file), $bucket === 'avatar' ? 'cold' : 'public');

    expect(fn () => MediaLibrary::adoptUpload($upload->token))->toThrow(FileUnacceptableForBucket::class);

    $ticket = ticketOf($upload);

    expect($ticket->status)->toBe(DirectUploadStatus::Rejected)
        ->and($ticket->failure_reason)->not->toBeEmpty()
        ->and(Storage::disk($ticket->disk)->exists($upload->key))->toBeFalse()
        ->and(Media::query()->count())->toBe(0)
        ->and(fn () => MediaLibrary::adoptUpload($upload->token))->toThrow(DirectUploadNotFound::class);

    Event::assertDispatched(DirectUploadHasBeenRejected::class);
})->with([
    'a spoofed type (html declared as png)' => ['polyglot', 'avatar', 64],
    'larger than declared' => ['sunrise.png', 'gallery', 100],
    'dimensions outside the box' => ['pixel.png', 'documents', 69],
]);

it('rejects bytes that do not match the declared sha256', function (): void {
    $upload = MediaLibrary::for(uploader())->upload('a.png', 69)->withSha256(str_repeat('a', 64))->toMediaBucket('gallery');
    sendBytes($upload, uploadFixture('pixel.png'));

    expect(fn () => MediaLibrary::adoptUpload($upload->token))->toThrow(FileUnacceptableForBucket::class, 'checksum')
        ->and(ticketOf($upload)->status)->toBe(DirectUploadStatus::Rejected);
});

it('shares identical content in the dedup scope and drops the upload', function (): void {
    Event::fake([DirectUploadHasBeenAdopted::class]);
    config()->set('media.deduplicate_scope', 'owner');
    $user = uploader();
    $existing = MediaLibrary::for($user)->add(__DIR__.'/../files/pixel.png')->toBucket('gallery');

    $upload = MediaLibrary::for($user)->upload('again.png', 69)->toMediaBucket('gallery');
    sendBytes($upload, uploadFixture('pixel.png'));
    $media = MediaLibrary::adoptUpload($upload->token);

    // Another owner's identical file is outside the scope: stored on its own.
    $other = MediaLibrary::for(uploader('Bea'))->upload('again.png', 69)->toMediaBucket('gallery');
    sendBytes($other, uploadFixture('pixel.png'));
    $otherMedia = MediaLibrary::adoptUpload($other->token);

    expect($media->getPath())->toBe($existing->getPath())
        ->and(Storage::disk('public')->exists($upload->key))->toBeFalse()
        ->and($otherMedia->getPath())->not->toBe($existing->getPath())
        ->and(Storage::disk('public')->exists($otherMedia->getPath()))->toBeTrue();

    Event::assertDispatched(DirectUploadHasBeenAdopted::class, fn (DirectUploadHasBeenAdopted $event): bool => $event->media->is($media) && $event->deduplicated);
});

it('lays the adopted file out with the host path generator', function (): void {
    app()->bind(PathGenerator::class, TenantPathGenerator::class);
    $user = uploader();
    $upload = MediaLibrary::for($user)->upload('a.png', 69)->toMediaBucket('gallery');
    sendBytes($upload, uploadFixture('pixel.png'));

    expect(MediaLibrary::adoptUpload($upload->token)->getPath())->toStartWith("tenants/{$user->id}/");
});

it('keeps one file in a single-file bucket', function (): void {
    $user = uploader();

    foreach (['first.png', 'second.png'] as $name) {
        $upload = MediaLibrary::for($user)->upload($name, 69)->toMediaBucket('avatar');
        sendBytes($upload, uploadFixture('pixel.png'), 'cold');
        $last = MediaLibrary::adoptUpload($upload->token);
    }

    expect(Media::query()->forModel($user)->inBucket('avatar')->pluck('id')->all())->toBe([$last->id])
        ->and($last->visibility)->toBe('private');
});

it('queues variants on the ticket queue', function (): void {
    Queue::fake();
    $upload = MediaLibrary::for(uploader())->upload('a.png', 117)->onQueue('media-heavy')->toMediaBucket('photos');
    sendBytes($upload, uploadFixture('wide.png'));

    $media = MediaLibrary::adoptUpload($upload->token);

    expect($media->hasGeneratedVariant('thumb'))->toBeTrue();
    Queue::assertPushedOn('media-heavy', GenerateVariantsJob::class);
});

it('adopts a draft that is bound later', function (): void {
    $user = uploader();
    $upload = MediaLibrary::upload('a.png', 69)->asDraft()->toBucket('gallery');
    sendBytes($upload, uploadFixture('pixel.png'));

    $draft = MediaLibrary::adoptUpload($upload->token);
    $bound = MediaLibrary::bindDraft((string) $draft->draft_token, $user, 'gallery');

    expect($draft->model_id)->toBeNull()
        ->and($bound->model_id)->toEqual($user->id);
});

it('puts the file back to staging when the host rolls the adoption back', function (): void {
    $upload = MediaLibrary::for(uploader())->upload('a.png', 69)->toMediaBucket('gallery');
    sendBytes($upload, uploadFixture('pixel.png'));

    try {
        DB::transaction(function () use ($upload): void {
            MediaLibrary::adoptUpload($upload->token);

            throw new RuntimeException('host failure');
        });
    } catch (RuntimeException) {
        // the host's own error
    }

    expect(Media::query()->count())->toBe(0)
        ->and(ticketOf($upload)->status)->toBe(DirectUploadStatus::Pending)
        ->and(Storage::disk('public')->get($upload->key))->toBe(uploadFixture('pixel.png'));

    expect(MediaLibrary::adoptUpload($upload->token)->exists)->toBeTrue();
});

it('requires the actor a ticket was issued for', function (): void {
    $actor = uploader('Actor');
    $upload = MediaLibrary::for(uploader())->upload('a.png', 69)->forActor($actor)->toMediaBucket('gallery');
    sendBytes($upload, uploadFixture('pixel.png'));

    expect(fn () => MediaLibrary::adoptUpload($upload->token))->toThrow(DirectUploadActorMismatch::class)
        ->and(fn () => MediaLibrary::adoptUpload($upload->token, uploader('Mallory')))->toThrow(DirectUploadActorMismatch::class)
        ->and(MediaLibrary::adoptUpload($upload->token, $actor)->exists)->toBeTrue();
});

it('refuses an expired ticket, and a token it does not know without echoing it', function (): void {
    $upload = MediaLibrary::for(uploader())->upload('a.png', 69)->expiresIn(5)->toMediaBucket('gallery');
    sendBytes($upload, uploadFixture('pixel.png'));
    [$id, $secret] = explode('.', $upload->token);

    try {
        MediaLibrary::adoptUpload($id.'.'.strrev($secret));
        $message = '';
    } catch (DirectUploadNotFound $exception) {
        $message = $exception->getMessage();
    }

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(6));

    expect($message)->not->toBe('')->not->toContain($secret)->not->toContain(strrev($secret))
        ->and(fn () => MediaLibrary::adoptUpload('garbage'))->toThrow(DirectUploadNotFound::class)
        ->and(fn () => MediaLibrary::adoptUpload($upload->token))->toThrow(DirectUploadExpired::class);

    CarbonImmutable::setTestNow();
});

it('adopts a trusted file from the disk without a ticket', function (): void {
    config()->set('media.direct_uploads.adoptable_prefixes', ['imports']);
    $user = uploader();
    Storage::disk('public')->put('imports/batch-1/wide.png', uploadFixture('wide.png'));

    $media = MediaLibrary::for($user)->adoptFromDisk('imports/batch-1/wide.png', 'public')->toMediaBucket('gallery');

    expect($media->getPath())->toBe($media->uuid.'/wide.png')
        ->and($media->checksum)->toBe(hash_file('sha256', __DIR__.'/../files/wide.png'))
        ->and(Storage::disk('public')->exists('imports/batch-1/wide.png'))->toBeFalse();
});

it('refuses a path outside the prefixes, one a media uses, or one a live ticket holds', function (): void {
    $user = uploader();
    $stored = MediaLibrary::for($user)->add(__DIR__.'/../files/pixel.png')->toBucket('gallery');
    $ticket = MediaLibrary::for($user)->upload('a.png', 69)->toMediaBucket('gallery');
    sendBytes($ticket, uploadFixture('pixel.png'));
    Storage::disk('public')->put('elsewhere/a.png', uploadFixture('pixel.png'));

    expect(fn () => MediaLibrary::adoptFromDisk('elsewhere/a.png', 'public'))->toThrow(DirectUploadsNotSupported::class)
        ->and(fn () => MediaLibrary::adoptFromDisk($stored->getPath(), 'public'))->toThrow(DirectUploadsNotSupported::class)
        ->and(fn () => MediaLibrary::adoptFromDisk($ticket->key, 'public'))->toThrow(DirectUploadsNotSupported::class)
        ->and(fn () => MediaLibrary::adoptFromDisk('_uploads/../elsewhere/a.png', 'public'))->toThrow(DirectUploadsNotSupported::class);
});

it('refuses to move an adopted file across disks that cannot copy in storage', function (): void {
    config()->set('media.direct_uploads.adoptable_prefixes', ['imports']);
    Storage::disk('hot')->put('imports/a.png', uploadFixture('pixel.png'));

    expect(fn () => MediaLibrary::for(uploader())->adoptFromDisk('imports/a.png', 'hot')->toMediaBucket('gallery'))
        ->toThrow(DirectUploadsNotSupported::class, 'cannot be moved')
        ->and(Storage::disk('hot')->exists('imports/a.png'))->toBeTrue();
});

it('aborts a pending upload and leaves an adopted one alone', function (): void {
    $user = uploader();
    $pending = MediaLibrary::for($user)->upload('a.png', 69)->toMediaBucket('gallery');
    sendBytes($pending, uploadFixture('pixel.png'));
    $adopted = MediaLibrary::for($user)->upload('b.png', 69)->toMediaBucket('gallery');
    sendBytes($adopted, uploadFixture('pixel.png'));
    MediaLibrary::adoptUpload($adopted->token);

    MediaLibrary::abortUpload($pending->token);
    MediaLibrary::abortUpload($adopted->token);

    expect(ticketOf($pending)->status)->toBe(DirectUploadStatus::Aborted)
        ->and(Storage::disk('public')->exists($pending->key))->toBeFalse()
        ->and(ticketOf($adopted)->status)->toBe(DirectUploadStatus::Adopted)
        ->and(fn () => MediaLibrary::adoptUpload($pending->token))->toThrow(DirectUploadNotFound::class);
});

it('prunes expired tickets, stale claims and staging leftovers, and nothing live', function (): void {
    config()->set('media.direct_uploads.prune_grace', 0);
    $user = uploader();
    $expired = MediaLibrary::for($user)->upload('a.png', 69)->expiresIn(1)->toMediaBucket('gallery');
    sendBytes($expired, uploadFixture('pixel.png'));
    $stale = MediaLibrary::for($user)->upload('b.png', 69)->expiresIn(1)->toMediaBucket('gallery');
    MediaUpload::query()->whereKey(ticketOf($stale)->id)->update(['status' => 'adopting', 'claimed_at' => CarbonImmutable::now()->subHour()]);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));

    $live = MediaLibrary::for($user)->upload('c.png', 69)->toMediaBucket('gallery');
    sendBytes($live, uploadFixture('pixel.png'));
    Storage::disk('public')->put('_uploads/orphan', 'left behind');
    touch(Storage::disk('public')->path('_uploads/orphan'), time() - 3600);
    touch(Storage::disk('public')->path($live->key), time() - 3600);

    $pruned = MediaLibrary::pruneUploads();

    expect($pruned)->toBe(3)
        ->and(ticketOf($expired)->status)->toBe(DirectUploadStatus::Expired)
        ->and(ticketOf($stale)->status)->toBe(DirectUploadStatus::Expired)
        ->and(ticketOf($live)->status)->toBe(DirectUploadStatus::Pending)
        ->and(Storage::disk('public')->exists($expired->key))->toBeFalse()
        ->and(Storage::disk('public')->exists('_uploads/orphan'))->toBeFalse()
        ->and(Storage::disk('public')->exists($live->key))->toBeTrue()
        ->and(fn () => MediaLibrary::adoptUpload($expired->token))->toThrow(DirectUploadExpired::class);

    $this->artisan('media:prune-uploads')->expectsOutputToContain('Pruned 0')->assertSuccessful();

    CarbonImmutable::setTestNow();
});

it('takes over an adopting claim that went stale, and waits for a fresh one', function (): void {
    $upload = MediaLibrary::for(uploader())->upload('a.png', 69)->toMediaBucket('gallery');
    sendBytes($upload, uploadFixture('pixel.png'));
    $ticket = ticketOf($upload);

    MediaUpload::query()->whereKey($ticket->id)->update(['status' => 'adopting', 'claimed_at' => CarbonImmutable::now()]);
    expect(fn () => MediaLibrary::adoptUpload($upload->token))->toThrow(DirectUploadIncomplete::class, 'another request');

    MediaUpload::query()->whereKey($ticket->id)->update(['claimed_at' => CarbonImmutable::now()->subMinutes(11)]);
    expect(MediaLibrary::adoptUpload($upload->token)->exists)->toBeTrue()
        ->and(fn () => MediaLibrary::abortUpload($upload->token))->not->toThrow(Throwable::class);
});

it('refuses builder input that would sign nonsense', function (): void {
    $pending = MediaLibrary::upload('a.png', 69);

    expect(fn () => $pending->withSha256('not-a-digest'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $pending->multipart(1024))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $pending->expiresIn(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $pending->withVisibility('Private'))->toThrow(InvalidVisibility::class);
});

it('signs a global private upload with custom properties on another disk', function (): void {
    $upload = MediaLibrary::upload('a.png', 69)->withVisibility('private')->useDisk('cold')
        ->withCustomProperties(['batch' => 7])->toBucket('library');
    sendBytes($upload, uploadFixture('pixel.png'), 'cold');

    $media = MediaLibrary::adoptUpload($upload->token);

    expect($media->disk)->toBe('cold')
        ->and($media->visibility)->toBe('private')
        ->and($media->custom_properties)->toBe(['batch' => 7])
        ->and($media->model_id)->toBeNull();
});

it('refuses to abort a ticket another request is adopting', function (): void {
    $upload = MediaLibrary::for(uploader())->upload('a.png', 69)->toMediaBucket('gallery');
    MediaUpload::query()->whereKey(ticketOf($upload)->id)->update(['status' => 'adopting', 'claimed_at' => CarbonImmutable::now()]);

    expect(fn () => MediaLibrary::abortUpload($upload->token))->toThrow(DirectUploadIncomplete::class);
});

it('rejects a ticket whose owner is gone', function (): void {
    $user = uploader();
    $upload = MediaLibrary::for($user)->upload('a.png', 69)->toMediaBucket('gallery');
    sendBytes($upload, uploadFixture('pixel.png'));
    $user->delete();

    expect(fn () => MediaLibrary::adoptUpload($upload->token))->toThrow(FileUnacceptableForBucket::class, 'no longer exists')
        ->and(ticketOf($upload)->status)->toBe(DirectUploadStatus::Rejected);
});
