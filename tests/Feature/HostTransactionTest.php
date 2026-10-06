<?php

declare(strict_types=1);

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Jobs\GenerateVariantsJob;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\RollbackCallbacks;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\EdgeCaseUser;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

/*
 * A host wraps media work in its own transaction (messages binds drafts inside `DB::transaction`
 * and rolls the send back on a bad token). Inside it the package's own transaction is only a
 * savepoint, so a file deleted "after commit" was deleted before the host's commit — and a host
 * rollback restored a row pointing at a file that no longer exists.
 */

/** Run `$work` inside a host transaction that then fails and rolls back. */
function insideRolledBackTransaction(Closure $work): void
{
    try {
        DB::transaction(function () use ($work): void {
            $work();

            throw new RuntimeException('the host gives up');
        });
    } catch (RuntimeException) {
        // Rolled back, as intended.
    }
}

it('keeps the source file of a move the host rolls back', function (): void {
    $user = TestUser::query()->create(['name' => 'Ada']);
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $path = $media->getPath();

    insideRolledBackTransaction(fn () => MediaLibrary::moveToDisk(Media::query()->findOrFail($media->id), 'cold'));

    $fresh = Media::query()->findOrFail($media->id);

    expect($fresh->disk)->toBe('public')
        ->and($fresh->getPath())->toBe($path);
    Storage::disk('public')->assertExists($path);
});

it('keeps the draft file of a bind the host rolls back', function (): void {
    $draft = MediaLibrary::draft(__DIR__.'/../files/pixel.png')->toBucket();
    $token = (string) $draft->draft_token;
    $owner = EdgeCaseUser::query()->create(['name' => 'Ada']);

    insideRolledBackTransaction(fn () => MediaLibrary::for($owner)->bindDraft($token, 'vaulted'));

    $fresh = Media::query()->findOrFail($draft->id);

    expect($fresh->disk)->toBe('public')
        ->and($fresh->draft_token)->toBe($token);
    Storage::disk('public')->assertExists($fresh->getPath());
});

it('keeps the variant files of a variants move the host rolls back', function (): void {
    Bus::fake();

    $user = TestUser::query()->create(['name' => 'Ada']);
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('photos');

    insideRolledBackTransaction(fn () => Media::query()->findOrFail($media->id)->moveVariantsToDisk('cold'));

    expect(Media::query()->findOrFail($media->id)->generatedVariant('thumb')?->disk)->toBe('hot');
    Storage::disk('hot')->assertExists($media->getPath('thumb'));
});

it('keeps the variant files a re-home drops when the host rolls back', function (): void {
    $user = TestUser::query()->create(['name' => 'Ada']);
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    insideRolledBackTransaction(fn () => Media::query()->findOrFail($media->id)->move($user, 'gallery'));

    expect(Media::query()->findOrFail($media->id)->hasGeneratedVariant('small'))->toBeTrue();
    Storage::disk('public')->assertExists($media->getPath('small'));
});

it('keeps the old original of a replace the host rolls back', function (): void {
    $user = TestUser::query()->create(['name' => 'Ada']);
    $existing = $user->addMedia(__DIR__.'/../files/sunrise.png')->toMediaBucket('gallery');
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $path = $media->getPath();

    // Identical bytes already stored: the replacement points at that file and releases its own.
    insideRolledBackTransaction(fn () => Media::query()->findOrFail($media->id)->replace(__DIR__.'/../files/sunrise.png'));

    expect(Media::query()->findOrFail($media->id)->getPath())->toBe($path)
        ->and($existing->getPath())->not->toBe($path);
    Storage::disk('public')->assertExists($path);
});

it('still removes the source files once the host commits', function (): void {
    $user = TestUser::query()->create(['name' => 'Ada']);
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $path = $media->getPath();

    DB::transaction(function () use ($media, $path): void {
        MediaLibrary::moveToDisk(Media::query()->findOrFail($media->id), 'cold');

        // Not yet: the host may still roll back.
        Storage::disk('public')->assertExists($path);
    });

    Storage::disk('public')->assertMissing($path);
    Storage::disk('cold')->assertExists($path);
});

it('spares a variant re-rendered onto the path of the one it replaces once the host commits', function (): void {
    $owner = EdgeCaseUser::query()->create(['name' => 'Ada']);
    $draft = MediaLibrary::for($owner)->add(__DIR__.'/../files/wide.png')->asDraft()->toMediaBucket('thumbs');
    $thumb = $draft->getPath('thumb');

    Storage::disk('public')->assertExists($thumb);

    DB::transaction(fn () => MediaLibrary::for($owner)->bindDraft((string) $draft->draft_token, 'thumbs-private'));

    $bound = Media::query()->findOrFail($draft->id);

    expect($bound->visibility)->toBe('private')
        ->and($bound->getPath('thumb'))->toBe($thumb);
    Storage::disk('public')->assertExists($thumb);
});

it('queues the variants job only once the host transaction commits', function (): void {
    config()->set('queue.default', 'sync');
    config()->set('queue.connections.sync.after_commit', false);

    $levels = [];
    Queue::before(static function (JobProcessing $event) use (&$levels): void {
        $levels[] = DB::transactionLevel();
    });

    $user = TestUser::query()->create(['name' => 'Ada']);

    $media = DB::transaction(function () use ($user): Media {
        $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('photos');

        // A worker picking the job up now would not see the row yet.
        expect(Media::query()->findOrFail($media->id)->hasGeneratedVariant('display'))->toBeFalse();

        return $media;
    });

    expect($levels)->toBe([0])
        ->and(Media::query()->findOrFail($media->id)->hasGeneratedVariant('display'))->toBeTrue();
});

it('drops the variants job when the host transaction rolls back', function (): void {
    config()->set('queue.default', 'sync');

    $ran = [];
    Queue::before(static function (JobProcessing $event) use (&$ran): void {
        $ran[] = $event->job->resolveName();
    });

    $user = TestUser::query()->create(['name' => 'Ada']);

    insideRolledBackTransaction(fn () => $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('photos'));

    expect($ran)->not->toContain(GenerateVariantsJob::class);
});

/*
 * An add writes its original and its synchronous variants before the host's transaction is
 * decided. When the host rolls the new row back, nothing points at those files any more.
 *
 * The committed-savepoint case is the one Laravel's own `afterRollBack()` gets wrong before 13.21
 * (all of 12.x included), which is why the package tracks transaction events itself.
 */

/**
 * Every file on the given disks, as `disk:path`.
 *
 * @return list<string>
 */
function filesOnDisks(string ...$disks): array
{
    $files = [];

    foreach ($disks as $disk) {
        foreach (Storage::disk($disk)->allFiles() as $path) {
            $files[] = "{$disk}:{$path}";
        }
    }

    sort($files);

    return $files;
}

/**
 * The files a media row points at — its original and every recorded variant — as `disk:path`.
 *
 * @return list<string>
 */
function filesOfMedia(Media ...$media): array
{
    $files = [];

    foreach ($media as $one) {
        $files[] = $one->disk.':'.$one->getPath();

        foreach (array_keys($one->generatedVariants()) as $variant) {
            $files[] = $one->diskFor($variant).':'.$one->getPath($variant);
        }
    }

    $files = array_values(array_unique($files));
    sort($files);

    return $files;
}

it('deletes the files of an add the host rolls back, variants included', function (): void {
    $user = TestUser::query()->create(['name' => 'Ada']);

    insideRolledBackTransaction(function () use ($user): void {
        $hero = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('hero');
        $cover = $user->addMedia(__DIR__.'/../files/sunrise.png')->toMediaBucket('covers');

        // Both originals and their variants (on two disks) really were written.
        expect($hero->generatedVariants())->toHaveCount(2)
            ->and($cover->generatedVariants())->toHaveCount(3)
            ->and(filesOnDisks('public', 'hot'))->toBe(filesOfMedia($hero, $cover));
    });

    expect(Media::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and(Storage::disk('hot')->allFiles())->toBe([]);
});

it('keeps the shared original of a deduplicated add the host rolls back', function (): void {
    $user = TestUser::query()->create(['name' => 'Ada']);
    $canonical = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('hero');
    $before = filesOnDisks('public', 'hot');

    insideRolledBackTransaction(function () use ($user, $canonical): void {
        $duplicate = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('hero');

        // Deduplicated: no bytes of its own for the original, but variants of its own.
        expect($duplicate->getPath())->toBe($canonical->getPath())
            ->and($duplicate->generatedVariants())->toHaveCount(2)
            ->and(filesOnDisks('public', 'hot'))->toBe(filesOfMedia($canonical, $duplicate));
    });

    expect(Media::query()->count())->toBe(1)
        ->and(filesOnDisks('public', 'hot'))->toBe($before);
    Storage::disk('public')->assertExists($canonical->getPath());
});

it('keeps the files of an add the host commits, queued variants included', function (): void {
    config()->set('queue.default', 'sync');

    $user = TestUser::query()->create(['name' => 'Ada']);

    $media = DB::transaction(fn (): Media => $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('photos'));

    $fresh = Media::query()->findOrFail($media->id);

    expect($fresh->hasGeneratedVariant('thumb'))->toBeTrue()
        ->and($fresh->hasGeneratedVariant('display'))->toBeTrue()
        ->and(filesOnDisks('public', 'hot'))->toBe(filesOfMedia($fresh));
});

it('keeps the files of a committed add when a later transaction rolls back', function (): void {
    $user = TestUser::query()->create(['name' => 'Ada']);
    $media = DB::transaction(fn (): Media => $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('hero'));

    insideRolledBackTransaction(fn () => TestUser::query()->create(['name' => 'Grace']));

    expect(filesOnDisks('public', 'hot'))->toBe(filesOfMedia(Media::query()->findOrFail($media->id)))
        ->and(filesOnDisks('public', 'hot'))->toHaveCount(3);
});

it('leaves no file of an add with queued variants the host rolls back', function (): void {
    config()->set('queue.default', 'sync');

    $user = TestUser::query()->create(['name' => 'Ada']);

    insideRolledBackTransaction(fn () => $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('photos'));

    // The sync `thumb` was written and is gone again; the queued `display` was never written.
    expect(filesOnDisks('public', 'hot'))->toBe([]);
});

it('deletes only the files of a savepoint the host rolls back inside a committed transaction', function (): void {
    $user = TestUser::query()->create(['name' => 'Ada']);

    [$outer, $savepoint] = DB::transaction(function () use ($user): array {
        $outer = $user->addMedia(__DIR__.'/../files/sunrise.png')->toMediaBucket('covers');

        // A savepoint that commits, then a sibling savepoint at the same level that rolls back.
        $savepoint = DB::transaction(fn (): Media => $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('hero'));

        insideRolledBackTransaction(fn () => $user->addMedia(__DIR__.'/../files/transparent.png')->toMediaBucket('covers'));

        return [$outer, $savepoint];
    });

    expect(Media::query()->count())->toBe(2)
        ->and(filesOnDisks('public', 'hot'))->toBe(filesOfMedia(
            Media::query()->findOrFail($outer->id),
            Media::query()->findOrFail($savepoint->id),
        ));
});

it('deletes the files of a committed savepoint once the outer transaction rolls back', function (): void {
    $user = TestUser::query()->create(['name' => 'Ada']);

    insideRolledBackTransaction(function () use ($user): void {
        DB::transaction(fn (): Media => $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('hero'));
    });

    expect(filesOnDisks('public', 'hot'))->toBe([]);
});

it('reports a delete that fails during the rollback, never masking the host error', function (): void {
    Exceptions::fake();

    $user = TestUser::query()->create(['name' => 'Ada']);
    $broken = Mockery::mock(Filesystem::class);
    $broken->shouldReceive('delete')->once()->andThrow(new RuntimeException('the disk is down'));

    expect(fn () => DB::transaction(function () use ($user, $broken): void {
        $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

        Storage::set('public', $broken);

        throw new RuntimeException('the host gives up');
    }))->toThrow(RuntimeException::class, 'the host gives up');

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'the disk is down');
});

it('registers nothing outside a transaction', function (): void {
    $ran = false;
    app(RollbackCallbacks::class)->register(DB::connection(), function () use (&$ran): void {
        $ran = true;
    });

    insideRolledBackTransaction(fn () => null);

    expect($ran)->toBeFalse();
});
