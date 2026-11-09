<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\DirectUpload;
use RoundlyConsulting\MediaLibrary\Enums\DirectUploadStatus;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Models\MediaUpload;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\FakeS3;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

/*
 * Two requests racing for one ticket — or a ticket and a prune, an abort, a delete of the
 * original it shares, another adoption into the same single-file bucket. Each needs two real
 * sessions, so these run on the pgsql leg: the second session must be HELD OFF (it times out on
 * the row the first one holds) and then, once the first commits, see a consistent result.
 */

$realEngine = static fn (): bool => config('database.connections.testing.driver') === 'pgsql';

beforeEach(function (): void {
    config()->set('media.direct_uploads.enabled', true);
    Storage::disk('public')->buildTemporaryUploadUrlsUsing(fn (string $path): array => ['url' => 'https://public.test/'.$path, 'headers' => []]);
    Storage::disk('cold')->buildTemporaryUploadUrlsUsing(fn (string $path): array => ['url' => 'https://cold.test/'.$path, 'headers' => []]);
});

/** Run `$work` on a SECOND session; false when a row lock held it off past the timeout. */
function onRacingSession(Closure $work): bool
{
    config()->set('database.connections.racer', config('database.connections.testing'));
    DB::purge('racer');
    DB::connection('racer')->statement("SET lock_timeout = '1s'");

    $default = DB::getDefaultConnection();
    DB::setDefaultConnection('racer');

    try {
        $work();

        return true;
    } catch (QueryException) {
        return false;
    } finally {
        DB::setDefaultConnection($default);
        DB::disconnect('racer');
    }
}

function racingUpload(TestUser $owner, string $bucket = 'gallery', string $disk = 'public'): DirectUpload
{
    $upload = MediaLibrary::for($owner)->upload('pixel.png', 69)->toMediaBucket($bucket);
    Storage::disk($disk)->put($upload->key, (string) file_get_contents(__DIR__.'/../files/pixel.png'));

    return $upload;
}

function racer(string $name = 'Ada'): TestUser
{
    return TestUser::query()->create(['name' => $name]);
}

it('lets one of two adoptions of a ticket through; the other then gets the same media', function (): void {
    $upload = racingUpload(racer());

    $media = DB::transaction(function () use ($upload): Media {
        $media = MediaLibrary::adoptUpload($upload->token);

        expect(onRacingSession(static fn () => MediaLibrary::adoptUpload($upload->token)))->toBeFalse();

        return $media;
    });

    $again = null;
    expect(onRacingSession(static function () use ($upload, &$again): void {
        $again = MediaLibrary::adoptUpload($upload->token);
    }))->toBeTrue()
        ->and($again?->id)->toBe($media->id)
        ->and(Media::query()->count())->toBe(1);
})->skip(fn (): bool => ! $realEngine(), 'needs two sessions on a real engine');

it('never lets a prune expire a ticket an adoption holds', function (): void {
    config()->set('media.direct_uploads.prune_grace', 0);
    $upload = racingUpload(racer());

    DB::transaction(function () use ($upload): void {
        MediaLibrary::adoptUpload($upload->token);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());
        expect(onRacingSession(static fn () => MediaLibrary::pruneUploads()))->toBeFalse();
    });

    $pruned = null;
    expect(onRacingSession(static function () use (&$pruned): void {
        $pruned = MediaLibrary::pruneUploads();
    }))->toBeTrue()
        ->and($pruned)->toBe(0)
        ->and(MediaUpload::query()->sole()->status)->toBe(DirectUploadStatus::Adopted);

    CarbonImmutable::setTestNow();
})->skip(fn (): bool => ! $realEngine(), 'needs two sessions on a real engine');

it('never lets an abort undo a multipart upload being completed', function (): void {
    $s3 = new FakeS3;
    config()->set('filesystems.disks.media-s3', [
        'driver' => 's3', 'key' => 'AKIATEST', 'secret' => 'secret', 'region' => 'eu-central-1', 'bucket' => 'media',
        'endpoint' => 'http://rustfs:9000', 'use_path_style_endpoint' => true, 'handler' => $s3,
    ]);
    Storage::forgetDisk('media-s3');

    $upload = MediaLibrary::for(racer())->upload('big.txt', 10)->multipart()->withVisibility('private')->useDisk('media-s3')->toMediaBucket('gallery');
    $etag = $s3->uploadPart((string) MediaUpload::query()->sole()->multipart_upload_id, 1, '0123456789');

    DB::transaction(function () use ($upload, $etag): void {
        MediaLibrary::completeUpload($upload->token, [1 => $etag]);

        expect(onRacingSession(static fn () => MediaLibrary::abortUpload($upload->token)))->toBeFalse();
    });

    expect(onRacingSession(static fn () => MediaLibrary::abortUpload($upload->token)))->toBeTrue()
        ->and(MediaUpload::query()->sole()->status)->toBe(DirectUploadStatus::Adopted)
        ->and($s3->objects)->toHaveKey(Media::query()->sole()->getPath());
})->skip(fn (): bool => ! $realEngine(), 'needs two sessions on a real engine');

it('keeps the original an adoption shares while another session purges it', function (): void {
    config()->set('media.deduplicate_scope', 'owner');
    $owner = racer();
    $canonical = MediaLibrary::for($owner)->add(__DIR__.'/../files/pixel.png')->toBucket('gallery');
    $upload = racingUpload($owner);

    DB::transaction(function () use ($upload, $canonical): void {
        expect(MediaLibrary::adoptUpload($upload->token)->getPath())->toBe($canonical->getPath())
            ->and(onRacingSession(static fn () => MediaModel::query()->withTrashed()->findOrFail($canonical->getKey())->forceDelete()))->toBeFalse();
    });

    expect(onRacingSession(static fn () => MediaModel::query()->withTrashed()->findOrFail($canonical->getKey())->forceDelete()))->toBeTrue();
    Storage::disk('public')->assertExists($canonical->getPath());
})->skip(fn (): bool => ! $realEngine(), 'needs two sessions on a real engine');

it('leaves exactly one media when two adoptions race into a single-file bucket', function (): void {
    $owner = racer();
    $first = racingUpload($owner, 'avatar', 'cold');
    $second = racingUpload($owner, 'avatar', 'cold');

    DB::transaction(function () use ($first, $second): void {
        MediaLibrary::adoptUpload($first->token);

        // Held off on the owner's row; its whole adoption rolls back and the ticket stays usable.
        expect(onRacingSession(static fn () => MediaLibrary::adoptUpload($second->token)))->toBeFalse();
    });

    expect(MediaUpload::query()->find(explode('.', $second->token)[0])?->status)->toBe(DirectUploadStatus::Pending)
        ->and(Storage::disk('cold')->exists($second->key))->toBeTrue()
        ->and(onRacingSession(static fn () => MediaLibrary::adoptUpload($second->token)))->toBeTrue()
        ->and(Media::query()->forModel($owner)->inBucket('avatar')->pluck('uuid')->all())->toBe([$second->mediaUuid]);
})->skip(fn (): bool => ! $realEngine(), 'needs two sessions on a real engine');
