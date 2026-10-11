<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Exceptions\FileCannotBeWritten;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;
use RoundlyConsulting\Testing\Fixtures\LockRecorder;
use RoundlyConsulting\Testing\Fixtures\LockRecordingGrammar;

/*
 * A row that starts sharing a stored original (a deduplicated add or replace, an `attach()`, a
 * same-disk `copy()`) is invisible to every other connection until it commits, and the reference
 * count is a plain read. A concurrent force-delete of the row it shares with would find no other
 * referrer and remove the file — then the sharing row commits, pointing at nothing.
 *
 * The real race needs two sessions, so it only runs on a real engine. The sqlite legs prove the
 * mechanism that closes it: the shared row is locked, inside the transaction that saves the
 * sharing row.
 */

function raceUser(string $name = 'Ada'): TestUser
{
    return TestUser::query()->create(['name' => $name]);
}

/** The ways a row comes to share `$shared`'s original, each returning the sharing row. */
function shareOnto(string $how, Media $shared, TestUser $owner): Media
{
    return match ($how) {
        'deduplicated add' => $owner->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery'),
        'deduplicated replace' => MediaLibrary::replace(
            $owner->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery'),
            __DIR__.'/../files/pixel.png',
        ),
        'attach' => MediaLibrary::attach($shared, $owner, 'gallery'),
        'same-disk copy' => MediaLibrary::copy($shared, $owner, 'gallery'),
    };
}

/**
 * Run `$work` through a SECOND session on the suite's database, outside any transaction. True when
 * it went through, false when a row lock held it off past the timeout.
 */
function onAnotherSession(Closure $work): bool
{
    config()->set('database.connections.purge', config('database.connections.testing'));
    DB::purge('purge');
    DB::connection('purge')->statement("SET lock_timeout = '1s'");

    $default = DB::getDefaultConnection();
    DB::setDefaultConnection('purge');

    try {
        $work();

        return true;
    } catch (QueryException) {
        return false;
    } finally {
        DB::setDefaultConnection($default);
        DB::disconnect('purge');
    }
}

/** Force-delete `$media` from another session — the account purge of the report. */
function purgeFromAnotherSession(Media $media): bool
{
    return onAnotherSession(static fn () => MediaModel::query()->withTrashed()->findOrFail($media->getKey())->forceDelete());
}

/** Replace `$media`'s file with `$file` from another session. */
function replaceFromAnotherSession(Media $media, string $file): bool
{
    return onAnotherSession(static fn () => MediaLibrary::replace(MediaModel::query()->findOrFail($media->getKey()), $file));
}

$realEngine = static fn (): bool => config('database.connections.testing.driver') === 'pgsql';

dataset('ways to share an original', ['deduplicated add', 'deduplicated replace', 'attach', 'same-disk copy']);

it('keeps an original a purge on another session races to delete while a row starts sharing it', function (string $how): void {
    $shared = raceUser('Bob')->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $path = $shared->getPath();

    $sharing = DB::transaction(function () use ($how, $shared): Media {
        $sharing = shareOnto($how, $shared, raceUser());

        // The purge lands before the sharing row commits. It must not get through: its reference
        // check could not see the sharing row.
        expect(purgeFromAnotherSession($shared))->toBeFalse();

        return $sharing;
    });

    expect($sharing->getPath())->toBe($path);
    Storage::disk('public')->assertExists($path);

    // Once the sharing row has committed the purge goes through, and the original survives it.
    expect(purgeFromAnotherSession($shared))->toBeTrue();
    Storage::disk('public')->assertExists($path);
})->with('ways to share an original')->skip(fn (): bool => ! $realEngine(), 'needs two sessions on a real engine');

it('locks the shared row inside the transaction that saves the sharing row', function (string $how): void {
    $shared = raceUser('Bob')->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $owner = raceUser();
    $sharer = $how === 'deduplicated replace'
        ? $owner->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery')
        : null;

    $connection = DB::connection();
    $connection->setQueryGrammar(new LockRecordingGrammar($connection));
    LockRecorder::flush();
    LockRecorder::listenForMarkers();

    // The sharing row's FIRST write is the one that points it at the original; later ones (its
    // variants) come after the commit.
    $saves = [];
    $record = static function (Media $media) use (&$saves, $connection, $shared): void {
        if (! $media->is($shared)) {
            $saves[] = ['locks' => count(LockRecorder::recorded()), 'depth' => $connection->transactionLevel()];
        }
    };
    Media::created($record);
    Media::updated($record);

    $sharing = $sharer === null ? shareOnto($how, $shared, $owner) : MediaLibrary::replace($sharer, __DIR__.'/../files/pixel.png');

    $locks = array_values(array_filter(
        LockRecorder::recorded(),
        static fn (array $lock): bool => str_contains($lock['sql'], 'media'),
    ));

    // A replace first locks its own row and reads, locking, whether its original is shared; the
    // shared row's lock comes last.
    $expected = $how === 'deduplicated replace'
        ? ['lock-for-update', 'lock-shared', 'lock-for-update']
        : ['lock-for-update'];
    $sharedLock = $locks[count($locks) - 1] ?? ['transactionDepth' => 0];

    expect($sharing->getPath())->toBe($shared->getPath())
        ->and(array_column($locks, 'marker'))->toBe($expected)
        ->and($sharedLock['transactionDepth'])->toBeGreaterThanOrEqual(1);

    // The sharing row is written after the lock, still inside the transaction that holds it.
    $sharingSave = $saves[0] ?? ['locks' => 0, 'depth' => 0];
    expect($sharingSave['locks'])->toBe(count($expected))
        ->and($sharingSave['depth'])->toBe($sharedLock['transactionDepth']);
})->with('ways to share an original')->skip(fn (): bool => $realEngine(), 'the lock recorder is sqlite-only; the engine locks for real');

it('writes its own copy when the original it would share disappears before the lock', function (): void {
    $shared = raceUser('Bob')->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    // The row goes between the dedup lookup and the lock (raw, so its file stays and the add has
    // nothing to notice but the missing row).
    $gone = false;
    DB::listen(static function (QueryExecuted $query) use ($shared, &$gone): void {
        if (! $gone && str_contains($query->sql, 'checksum')) {
            $gone = true;
            DB::table('media')->where('id', $shared->getKey())->delete();
        }
    });

    $media = raceUser()->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    expect($gone)->toBeTrue()
        ->and($media->getPath())->not->toBe($shared->getPath());
    Storage::disk('public')->assertExists($media->getPath());
});

it('refuses to attach or copy from a row that is gone by the time it is locked', function (string $how): void {
    $shared = raceUser('Bob')->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    DB::table('media')->where('id', $shared->getKey())->delete();

    expect(fn () => shareOnto($how, $shared, raceUser()))->toThrow(ModelNotFoundException::class)
        ->and(Media::query()->count())->toBe(0);
})->with(['attach', 'same-disk copy']);

it('keeps the files of a force-delete the host rolls back', function (): void {
    $media = raceUser()->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $path = $media->getPath();

    try {
        DB::transaction(function () use ($media): void {
            $media->forceDelete();

            throw new RuntimeException('host failure');
        });
    } catch (RuntimeException) {
        // the host's own error
    }

    expect(Media::query()->whereKey($media->getKey())->exists())->toBeTrue();
    Storage::disk('public')->assertExists($path);
});

it('removes the files of a force-delete only once the host commits', function (): void {
    $media = raceUser()->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $path = $media->getPath();

    DB::transaction(function () use ($media, $path): void {
        $media->forceDelete();

        Storage::disk('public')->assertExists($path);
    });

    Storage::disk('public')->assertMissing($path);
});

it('keeps a deduplicated row on its own bytes when another session replaces the row it shares', function (): void {
    $media = raceUser('Bob')->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $pixel = (string) file_get_contents(__DIR__.'/../files/pixel.png');

    $dedup = DB::transaction(function () use ($media): Media {
        $dedup = raceUser()->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

        // Same type, sole committed referrer: the replace would overwrite the file in place. It
        // must not reach the bytes before the deduplicated row has committed.
        expect(replaceFromAnotherSession($media, __DIR__.'/../files/wide.png'))->toBeFalse();

        return $dedup;
    });

    expect($dedup->getPath())->toBe($media->getPath())
        ->and(Storage::disk('public')->get($dedup->getPath()))->toBe($pixel);

    // Once it has, the replace sees the new referrer and writes a path of its own instead.
    expect(replaceFromAnotherSession($media, __DIR__.'/../files/wide.png'))->toBeTrue();

    $replaced = Media::query()->findOrFail($media->getKey());
    expect($replaced->getPath())->not->toBe($dedup->getPath())
        ->and(Storage::disk('public')->get($dedup->getPath()))->toBe($pixel)
        ->and(Storage::disk('public')->get($replaced->getPath()))->toBe((string) file_get_contents(__DIR__.'/../files/wide.png'));
})->skip(fn (): bool => ! $realEngine(), 'needs two sessions on a real engine');

it('locks the replaced row before it decides whether its original is shared', function (): void {
    $media = raceUser()->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $path = $media->getPath();

    $connection = DB::connection();
    $connection->setQueryGrammar(new LockRecordingGrammar($connection));
    LockRecorder::flush();
    LockRecorder::listenForMarkers();

    $saves = [];
    Media::updated(static function () use (&$saves, $connection): void {
        $saves[] = ['locks' => count(LockRecorder::recorded()), 'depth' => $connection->transactionLevel()];
    });

    MediaLibrary::replace($media, __DIR__.'/../files/wide.png');

    $locks = array_values(array_filter(
        LockRecorder::recorded(),
        static fn (array $lock): bool => str_contains($lock['sql'], 'media'),
    ));

    // In place (same type, nobody else's file): the row lock first, then the sharedness check as
    // a locking read, both in one transaction that the save is still part of.
    expect($media->getPath())->toBe($path)
        ->and(array_column($locks, 'marker'))->toBe(['lock-for-update', 'lock-shared'])
        ->and($locks[0]['transactionDepth'])->toBeGreaterThanOrEqual(1)
        ->and($locks[1]['transactionDepth'])->toBe($locks[0]['transactionDepth'])
        ->and($saves[0]['locks'] ?? 0)->toBe(2)
        ->and($saves[0]['depth'] ?? 0)->toBe($locks[0]['transactionDepth']);
})->skip(fn (): bool => $realEngine(), 'the lock recorder is sqlite-only; the engine locks for real');

it('puts back the bytes an in-place replace overwrote when the host rolls back', function (): void {
    $media = raceUser()->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $path = $media->getPath();
    $pixel = (string) file_get_contents(__DIR__.'/../files/pixel.png');

    try {
        DB::transaction(function () use ($media, $path): void {
            MediaLibrary::replace($media, __DIR__.'/../files/wide.png');

            expect($media->getPath())->toBe($path)
                ->and(Storage::disk('public')->get($path))->not->toBe(file_get_contents(__DIR__.'/../files/pixel.png'));

            throw new RuntimeException('host failure');
        });
    } catch (RuntimeException) {
        // the host's own error
    }

    expect(Media::query()->findOrFail($media->getKey())->getPath())->toBe($path)
        ->and(Storage::disk('public')->get($path))->toBe($pixel)
        ->and(Storage::disk('public')->allFiles())->toBe([$path]);
});

it('keeps the URL of an in-place replace and leaves nothing else behind once it commits', function (): void {
    $media = raceUser()->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $path = $media->getPath();

    DB::transaction(fn () => MediaLibrary::replace($media, __DIR__.'/../files/wide.png'));

    expect(Media::query()->findOrFail($media->getKey())->getPath())->toBe($path)
        ->and(Storage::disk('public')->get($path))->toBe(file_get_contents(__DIR__.'/../files/wide.png'))
        ->and(Storage::disk('public')->allFiles())->toBe([$path]);
});

it('deletes the new file of a replace written elsewhere when the host rolls back', function (): void {
    $media = raceUser()->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $path = $media->getPath();

    try {
        DB::transaction(function () use ($media): void {
            // Another type renames the stored file, so the new bytes go to a path of their own.
            MediaLibrary::replace($media, __DIR__.'/../files/landscape.jpg');

            throw new RuntimeException('host failure');
        });
    } catch (RuntimeException) {
        // the host's own error
    }

    expect(Media::query()->findOrFail($media->getKey())->getPath())->toBe($path)
        ->and(Storage::disk('public')->allFiles())->toBe([$path]);
});

it('refuses to replace a row that is gone by the time it is locked', function (): void {
    $media = raceUser()->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $path = $media->getPath();
    DB::table('media')->where('id', $media->getKey())->delete();

    expect(fn () => MediaLibrary::replace($media, __DIR__.'/../files/wide.png'))->toThrow(ModelNotFoundException::class)
        ->and(Storage::disk('public')->get($path))->toBe(file_get_contents(__DIR__.'/../files/pixel.png'));
});

it('writes in place without a backup when the old original is already missing', function (): void {
    $media = raceUser()->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $path = $media->getPath();
    Storage::disk('public')->delete($path);

    DB::transaction(fn () => MediaLibrary::replace($media, __DIR__.'/../files/wide.png'));

    expect(Storage::disk('public')->allFiles())->toBe([$path])
        ->and(Storage::disk('public')->get($path))->toBe(file_get_contents(__DIR__.'/../files/wide.png'));
});

it('refuses an in-place replace whose old bytes cannot be kept for a rollback', function (): void {
    $media = raceUser()->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $path = $media->getPath();
    $checksum = $media->checksum;

    // A disk configured with `'throw' => false` reports a refused copy as `false`.
    $disk = Mockery::mock(Storage::disk('public'))->makePartial();
    $disk->shouldReceive('copy')->andReturnFalse();
    Storage::set('public', $disk);

    expect(fn () => MediaLibrary::replace($media, __DIR__.'/../files/wide.png'))->toThrow(FileCannotBeWritten::class)
        ->and(Media::query()->findOrFail($media->getKey())->checksum)->toBe($checksum)
        ->and(Storage::disk('public')->get($path))->toBe(file_get_contents(__DIR__.'/../files/pixel.png'));
});

it('reports a restore that fails during the rollback, never masking the host error', function (): void {
    Exceptions::fake();

    $media = raceUser()->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    expect(fn () => DB::transaction(function () use ($media): void {
        MediaLibrary::replace($media, __DIR__.'/../files/wide.png');

        // The copy kept aside is gone by the time the rollback wants it back.
        foreach (Storage::disk('public')->directories($media->uuid) as $directory) {
            Storage::disk('public')->deleteDirectory($directory);
        }

        throw new RuntimeException('the host gives up');
    }))->toThrow(RuntimeException::class, 'the host gives up');

    Exceptions::assertReported(FileCannotBeWritten::class);
});
