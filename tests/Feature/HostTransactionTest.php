<?php

declare(strict_types=1);

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Jobs\GenerateVariantsJob;
use RoundlyConsulting\MediaLibrary\Models\Media;
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
