<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\MediaLibrary\Exceptions\DraftMediaExpired;
use RoundlyConsulting\MediaLibrary\Exceptions\DraftMediaNotFound;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Jobs\GenerateVariantsJob;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Testing\MediaLibraryFake;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

function fakeUser(string $name = 'Jane'): TestUser
{
    return TestUser::query()->create(['name' => $name]);
}

const FAKE_PIXEL = __DIR__.'/../files/pixel.png';

it('swaps a recording fake into the facade and the container', function (): void {
    $fake = MediaLibrary::fake();

    expect($fake)->toBeInstanceOf(MediaLibraryFake::class)
        ->and($fake)->toBeInstanceOf(MediaLibraryManager::class)
        ->and(app(MediaLibraryManager::class))->toBe($fake)
        ->and(MediaLibrary::getFacadeRoot())->toBe($fake);
});

it('performs nothing: no file, row, job or event', function (): void {
    $user = fakeUser();
    Event::fake();
    Bus::fake();
    MediaLibrary::fake();

    $owned = $user->addMedia(__DIR__.'/../files/wide.png')->usingName('Wide')->toMediaBucket('photos');
    $global = MediaLibrary::add(FAKE_PIXEL)->useDisk('cold')->withVisibility('private')->toBucket('brand');

    expect(Media::query()->count())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([])
        ->and(Storage::disk('hot')->allFiles())->toBe([])
        ->and($owned->exists)->toBeFalse()
        ->and($owned->uuid)->not->toBe('')
        ->and($owned->name)->toBe('Wide')
        ->and($owned->file_name)->toBe('wide.png')
        ->and($owned->model_type)->toBe($user->getMorphClass())
        ->and($owned->bucket_name)->toBe('photos')
        ->and($owned->disk)->toBe('public')
        ->and($global->model_type)->toBeNull()
        ->and($global->disk)->toBe('cold')
        ->and(Storage::disk('cold')->allFiles())->toBe([])
        ->and($global->visibility)->toBe('private');

    Event::assertNothingDispatched();
    Bus::assertNothingDispatched();
});

it('still enforces bucket acceptance on a faked add', function (): void {
    $user = fakeUser();
    $fake = MediaLibrary::fake();

    expect(fn () => $user->addMediaFromString('plain')->usingFileName('a.txt')->toMediaBucket('avatar'))
        ->toThrow(FileUnacceptableForBucket::class);

    $fake->assertNothingAdded();
});

it('asserts adds, including those made through the model trait', function (): void {
    $user = fakeUser();
    $fake = MediaLibrary::fake();

    $fake->assertNothingAdded();

    $user->addMedia(FAKE_PIXEL)->toMediaBucket('gallery');

    $fake->assertAdded();
    $fake->assertAdded('gallery', $user);
    expect(fn () => $fake->assertAdded('avatar'))->toThrow(ExpectationFailedException::class, 'bucket [avatar]')
        ->and(fn () => $fake->assertAdded('gallery', fakeUser('John')))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNothingAdded())->toThrow(ExpectationFailedException::class, '1 call(s)');
});

it('asserts attaches, including those made through the model trait', function (): void {
    $user = fakeUser();
    $source = Media::factory()->create();
    $fake = MediaLibrary::fake();

    $fake->assertNothingAttached();

    $attached = $user->attachMedia($source, 'gallery');

    expect($attached->uuid)->not->toBe($source->uuid)
        ->and($attached->exists)->toBeFalse()
        ->and($attached->bucket_name)->toBe('gallery')
        ->and($attached->model_type)->toBe($user->getMorphClass());

    $fake->assertAttached($source, $user, 'gallery');
    expect(fn () => $fake->assertAttached($source, bucket: 'other'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertAttached(Media::factory()->create()))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNothingAttached())->toThrow(ExpectationFailedException::class);

    MediaLibrary::attach($source, bucket: 'shared');
    $fake->assertAttached($source, bucket: 'shared');
});

it('asserts draft binds made through the model trait, resolving fake and stored drafts', function (): void {
    $user = fakeUser();
    $stored = Media::factory()->create(['draft_token' => 'stored-token', 'draft_expires_at' => now()->addHour()]);
    $fake = MediaLibrary::fake();

    $fake->assertNothingBound();

    $draft = MediaLibrary::draft(FAKE_PIXEL)->toBucket('avatar');
    $bound = $user->attachDraftMedia((string) $draft->draft_token, 'avatar');

    expect($bound)->toBe($draft)
        ->and($bound->draft_token)->toBeNull()
        ->and($bound->model_type)->toBe($user->getMorphClass());

    $fromDb = MediaLibrary::for($user)->bindDraft('stored-token', 'gallery');
    expect($fromDb->is($stored))->toBeTrue()
        ->and($stored->fresh()?->draft_token)->toBe('stored-token');

    $fake->assertDraftBound();
    $fake->assertDraftBound('stored-token', $user, 'gallery');
    expect(fn () => $fake->assertDraftBound('unknown'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNothingBound())->toThrow(ExpectationFailedException::class);
});

it('keeps the draft contract under the fake: unknown and expired tokens throw', function (): void {
    $user = fakeUser();
    Media::factory()->create(['draft_token' => 'old', 'draft_expires_at' => now()->subMinute()]);
    $fake = MediaLibrary::fake();

    expect(fn () => $user->attachDraftMedia('never-issued'))->toThrow(DraftMediaNotFound::class)
        ->and(fn () => $user->attachDraftMedia('old'))->toThrow(DraftMediaExpired::class);

    $fake->assertNothingBound();
});

it('asserts moves made through the model methods and re-points in memory only', function (): void {
    $user = fakeUser();
    $media = Media::factory()->create(['bucket_name' => 'brand']);
    $fake = MediaLibrary::fake();

    $fake->assertNothingMoved();

    $media->move($user, 'gallery', 'cold');
    expect($media->bucket_name)->toBe('gallery')
        ->and($media->disk)->toBe('cold')
        ->and($media->fresh()?->bucket_name)->toBe('brand');

    $media->moveToDisk('hot');

    $fake->assertMoved($media);
    $fake->assertMoved($media, $user, 'gallery', 'cold');
    $fake->assertMoved($media, $user, 'gallery', 'hot');
    expect(fn () => $fake->assertMoved($media, disk: 's3'))->toThrow(ExpectationFailedException::class, 'disk [s3]')
        ->and(fn () => $fake->assertMoved(to: fakeUser('John')))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNothingMoved())->toThrow(ExpectationFailedException::class);
});

it('asserts variant moves made through the model method', function (): void {
    $media = Media::factory()->create();
    $fake = MediaLibrary::fake();

    $fake->assertNoVariantsMoved();

    $media->moveVariantsToDisk('cold');

    expect($media->variants_disk)->toBe('cold');
    $fake->assertVariantsMoved($media, 'cold');
    expect(fn () => $fake->assertVariantsMoved($media, 'hot'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNoVariantsMoved())->toThrow(ExpectationFailedException::class);

    MediaLibrary::moveVariantsToDisk($media, 'public');
    expect($media->variants_disk)->toBeNull();
});

it('asserts copies made through the model method', function (): void {
    $user = fakeUser();
    $media = Media::factory()->create();
    $fake = MediaLibrary::fake();

    $fake->assertNothingCopied();

    $copy = $media->copy($user, 'gallery', 'cold');

    expect($copy->uuid)->not->toBe($media->uuid)
        ->and($copy->disk)->toBe('cold')
        ->and($copy->exists)->toBeFalse()
        ->and(Media::query()->count())->toBe(1);

    $fake->assertCopied($media, $user, 'gallery', 'cold');
    expect(fn () => $fake->assertCopied($media, bucket: 'other'))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNothingCopied())->toThrow(ExpectationFailedException::class);
});

it('asserts replaces made through the model method', function (): void {
    $media = Media::factory()->create();
    $other = Media::factory()->create();
    $fake = MediaLibrary::fake();

    $fake->assertNothingReplaced();

    expect($media->replace(FAKE_PIXEL))->toBe($media);

    $fake->assertReplaced($media);
    expect(fn () => $fake->assertReplaced($other))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNothingReplaced())->toThrow(ExpectationFailedException::class);
});

it('asserts deletes made through the model method and the trait, keeping every row', function (): void {
    $user = fakeUser();
    $single = Media::factory()->create();
    $a = Media::factory()->create(['model_type' => $user->getMorphClass(), 'model_id' => $user->getKey(), 'bucket_name' => 'gallery']);
    $b = Media::factory()->create(['model_type' => $user->getMorphClass(), 'model_id' => $user->getKey(), 'bucket_name' => 'gallery']);
    $fake = MediaLibrary::fake();

    $fake->assertNothingDeleted();

    $single->deleteWithFiles();
    $user->clearMediaBucket('gallery');

    $fake->assertDeleted($single);
    $fake->assertDeleted($a);
    $fake->assertDeleted($b);
    expect(Media::query()->count())->toBe(3)
        ->and(fn () => $fake->assertDeleted(Media::factory()->create()))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNothingDeleted())->toThrow(ExpectationFailedException::class, '3 call(s)');
});

it('asserts regenerations made through variants() and the queued job', function (): void {
    $media = Media::factory()->create();
    $fake = MediaLibrary::fake();

    $fake->assertNothingRegenerated();

    expect(MediaLibrary::variants($media)->regenerate(only: ['thumb']))->toBe([]);
    (new GenerateVariantsJob((int) $media->getKey(), ['display']))->handle(app(MediaLibraryManager::class));

    $fake->assertRegenerated($media);
    $fake->assertRegenerated($media, ['thumb'], false);
    $fake->assertRegenerated($media, ['display'], true);
    expect(fn () => $fake->assertRegenerated($media, ['thumb'], true))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertRegenerated(Media::factory()->create()))->toThrow(ExpectationFailedException::class)
        ->and(fn () => $fake->assertNothingRegenerated())->toThrow(ExpectationFailedException::class);
});

it('asserts draft prunes made through the facade or the command', function (): void {
    Media::factory()->create(['draft_token' => 'old', 'draft_expires_at' => now()->subMinute()]);
    $fake = MediaLibrary::fake();

    $fake->assertDraftsNotPruned();
    expect(fn () => $fake->assertDraftsPruned())->toThrow(ExpectationFailedException::class);

    $this->artisan('media:prune-drafts')->expectsOutput('Pruned 0 expired draft media.')->assertSuccessful();

    $fake->assertDraftsPruned();
    expect(Media::query()->count())->toBe(1)
        ->and(MediaLibrary::pruneDrafts())->toBe(0)
        ->and(fn () => $fake->assertDraftsNotPruned())->toThrow(ExpectationFailedException::class);
});

it('discards the temporary copy a faked add was normalized into', function (): void {
    $fake = MediaLibrary::fake();

    // The system temp dir is shared with every parallel process, each creating and deleting its
    // own `media_*` copies mid-test, so a before/after count raced them. Look for THIS add's copy
    // by its bytes instead.
    $bytes = 'bytes-'.bin2hex(random_bytes(8));
    $before = glob(sys_get_temp_dir().'/media_*') ?: [];

    MediaLibrary::addFromString($bytes)->usingFileName('a.txt')->toBucket('docs');

    $survivors = array_filter(
        array_diff(glob(sys_get_temp_dir().'/media_*') ?: [], $before),
        static fn (string $path): bool => @file_get_contents($path) === $bytes,
    );

    expect($survivors)->toBeEmpty();
    $fake->assertAdded('docs');
});

it('discards the temporary copy of a faked add the bucket rejects', function (): void {
    MediaLibrary::fake();
    $bytes = 'rejected-'.bin2hex(random_bytes(8));
    $user = TestUser::query()->create(['name' => 'Mallory']);

    expect(fn () => MediaLibrary::for($user)->addFromString($bytes)->toBucket('avatar'))
        ->toThrow(FileUnacceptableForBucket::class);

    $survivors = array_filter(
        glob(sys_get_temp_dir().'/media_*') ?: [],
        static fn (string $path): bool => @file_get_contents($path) === $bytes,
    );

    expect($survivors)->toBeEmpty();
});

it('ignores a queued variant job that names no variants', function (): void {
    $fake = MediaLibrary::fake();

    (new GenerateVariantsJob((int) Media::factory()->create()->getKey(), []))->handle(app(MediaLibraryManager::class));

    $fake->assertNothingRegenerated();
});

it('enforces the bucket size and dimension rules on a faked add', function (): void {
    $user = fakeUser();
    $fake = MediaLibrary::fake();

    expect(fn () => MediaLibrary::for($user)->add(__DIR__.'/../files/wide.png')->toBucket('documents'))
        ->toThrow(FileUnacceptableForBucket::class, 'dimensions');

    config()->set('media.max_file_size', 10);

    expect(fn () => MediaLibrary::add(FAKE_PIXEL)->toBucket('brand'))
        ->toThrow(FileUnacceptableForBucket::class, 'larger');

    $fake->assertNothingAdded();
});

it('stores the same safe file name under the fake as for real', function (): void {
    MediaLibrary::fake();

    $media = MediaLibrary::addFromString('hello')->usingFileName('../../evil.html')->toBucket('brand');

    expect($media->file_name)->toBe('evil.txt')
        ->and($media->extension)->toBe('txt');
});

it('enforces the target bucket on a faked draft bind', function (): void {
    $user = fakeUser();
    $fake = MediaLibrary::fake();

    $draft = MediaLibrary::draft(__DIR__.'/../files/note.txt')->toBucket('avatar');

    expect(fn () => MediaLibrary::for($user)->bindDraft((string) $draft->draft_token, 'avatar'))
        ->toThrow(FileUnacceptableForBucket::class);

    $fake->assertNothingBound();
});
