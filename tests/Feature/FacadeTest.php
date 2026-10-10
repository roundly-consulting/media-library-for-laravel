<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Events\DraftMediaHasBeenBound;
use RoundlyConsulting\MediaLibrary\Events\MediaHasBeenDeleted;
use RoundlyConsulting\MediaLibrary\Exceptions\FileDoesNotExist;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\MediaLibrary\Exceptions\MediaDoesNotBelongToModel;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Handles\MediaVariants;
use RoundlyConsulting\MediaLibrary\Handles\ModelMedia;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

function facadeUser(string $name = 'Jane'): TestUser
{
    return TestUser::query()->create(['name' => $name]);
}

it('documents its root, is fakeable, and reaches every host-facing action', function (): void {
    expect(MediaLibrary::class)
        ->toDocumentItsRoot()
        ->toBeFakeable()
        ->toReachEveryAction(__DIR__.'/../../src/Actions')
        ->toRedactSensitiveArguments(methods: 1);
});

// --- for($model): owner-scoped adds ---

it('adds owner-scoped media through for() with the bucket rules applied', function (): void {
    $user = facadeUser();

    $media = MediaLibrary::for($user)->add(__DIR__.'/../files/pixel.png')->toBucket('avatar');

    expect(MediaLibrary::for($user))->toBeInstanceOf(ModelMedia::class)
        ->and($media->model_type)->toBe($user->getMorphClass())
        ->and((string) $media->model_id)->toBe((string) $user->getKey())
        ->and($media->disk)->toBe('cold')
        ->and($media->visibility)->toBe('private');

    Storage::disk('cold')->assertExists($media->getPath());

    expect(fn () => MediaLibrary::for($user)->addFromString('plain')->usingFileName('a.txt')->toBucket('avatar'))
        ->toThrow(FileUnacceptableForBucket::class);
});

it('adds owner-scoped media from every source through for()', function (): void {
    Http::fake(['*' => Http::response('url-bytes', 200, ['Content-Type' => 'text/plain'])]);
    Storage::disk('public')->put('seed/disk.txt', 'disk-bytes');

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, 'stream-bytes');
    rewind($stream);

    $user = facadeUser();
    $handle = MediaLibrary::for($user);

    $added = [
        $handle->addFromUrl('https://example.com/a.txt')->toBucket('gallery'),
        $handle->addFromDisk('seed/disk.txt')->toBucket('gallery'),
        $handle->addFromString('string-bytes')->usingFileName('s.txt')->toBucket('gallery'),
        $handle->addFromBase64(base64_encode('b64-bytes'))->usingFileName('b.txt')->toBucket('gallery'),
        $handle->addFromStream($stream)->usingFileName('st.txt')->toBucket('gallery'),
    ];
    fclose($stream);

    expect(collect($added)->map(fn (Media $m): string => (string) Storage::disk('public')->get($m->getPath()))->all())
        ->toBe(['url-bytes', 'disk-bytes', 'string-bytes', 'b64-bytes', 'stream-bytes'])
        ->and($handle->get('gallery'))->toHaveCount(5);
});

it('adds the request upload under a key through for()', function (): void {
    $user = facadeUser();
    request()->files->set('photo', UploadedFile::fake()->image('photo.png'));

    $media = MediaLibrary::for($user)->addFromRequest('photo')->toBucket('gallery');

    expect($media->file_name)->toBe('photo.png')
        ->and(MediaLibrary::for($user)->has('gallery'))->toBeTrue();
});

it('refuses a request key that carries no upload', function (): void {
    expect(fn () => MediaLibrary::for(facadeUser())->addFromRequest('missing'))
        ->toThrow(FileDoesNotExist::class, 'no uploaded file under [missing]');
});

// Regression: the trait passed `request()->file($key)` straight into a `string|UploadedFile`
// parameter, so a missing key surfaced as a TypeError instead of the package's exception.
it('raises the package exception, not a TypeError, for a missing request upload on the model', function (): void {
    expect(fn () => facadeUser()->addMediaFromRequest('missing'))->toThrow(FileDoesNotExist::class);
});

// --- for($model): drafts, attach, reads ---

it('binds a draft through for()', function (): void {
    Event::fake([DraftMediaHasBeenBound::class]);
    $user = facadeUser();
    $draft = MediaLibrary::draft(__DIR__.'/../files/pixel.png')->toBucket('avatar');

    $bound = MediaLibrary::for($user)->bindDraft((string) $draft->draft_token, 'gallery');

    expect($bound->is($draft))->toBeTrue()
        ->and($bound->bucket_name)->toBe('gallery')
        ->and($bound->draft_token)->toBeNull()
        ->and(MediaLibrary::for($user)->first('gallery')?->is($draft))->toBeTrue();

    Event::assertDispatched(DraftMediaHasBeenBound::class);
});

it('binds a draft through the flat verb', function (): void {
    $user = facadeUser();
    $draft = MediaLibrary::draft(__DIR__.'/../files/pixel.png')->toBucket('avatar');

    $bound = MediaLibrary::bindDraft((string) $draft->draft_token, to: $user, bucket: 'avatar');

    expect($bound->model_type)->toBe($user->getMorphClass())
        ->and($bound->bucket_name)->toBe('avatar');
});

it('attaches by reference through for() and to a global bucket through the flat verb', function (): void {
    $user = facadeUser();
    $logo = MediaLibrary::add(__DIR__.'/../files/pixel.png')->toBucket('brand');

    $owned = MediaLibrary::for($user)->attach($logo, 'gallery');
    $shared = MediaLibrary::attach($logo, bucket: 'shared');

    expect($owned->getPath())->toBe($logo->getPath())
        ->and($owned->model_type)->toBe($user->getMorphClass())
        ->and($owned->bucket_name)->toBe('gallery')
        ->and($shared->model_type)->toBeNull()
        ->and($shared->bucket_name)->toBe('shared')
        ->and($shared->uuid)->not->toBe($logo->uuid)
        ->and(MediaLibrary::bucket('shared')->get())->toHaveCount(1);
});

it('reads only its own owner through for()', function (): void {
    $jane = facadeUser('Jane');
    $john = facadeUser('John');
    $mine = $jane->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $theirs = $john->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    $handle = MediaLibrary::for($jane);

    expect($handle->get('gallery')->pluck('id')->all())->toBe([$mine->id])
        ->and($handle->first('gallery')?->is($mine))->toBeTrue()
        ->and($handle->has('gallery'))->toBeTrue()
        ->and($handle->has('photos'))->toBeFalse()
        ->and($handle->find($mine->uuid)?->is($mine))->toBeTrue()
        ->and($handle->find($theirs->uuid))->toBeNull();
});

it('resolves the first url, a temporary url, and the bucket fallback through for()', function (): void {
    $user = facadeUser();
    $handle = MediaLibrary::for($user);

    expect($handle->url('avatar'))->toBe('https://example.com/fallback-avatar.png')
        ->and($handle->temporaryUrl('avatar'))->toBe('https://example.com/fallback-avatar.png')
        ->and($handle->url('gallery'))->toBe('');

    $media = $handle->add(__DIR__.'/../files/pixel.png')->toBucket('gallery');

    expect($handle->url('gallery'))->toBe($media->getUrl())
        ->and($handle->temporaryUrl('gallery', expiry: now()->addMinute()))->toContain($media->file_name);
});

it('clears one owner bucket through for(), firing a delete event per media', function (): void {
    Event::fake([MediaHasBeenDeleted::class]);
    $jane = facadeUser('Jane');
    $john = facadeUser('John');
    $a = $jane->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $jane->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $johns = $john->addMedia(__DIR__.'/../files/square.webp')->toMediaBucket('gallery');

    expect(MediaLibrary::for($jane)->clear('gallery'))->toBe(2)
        ->and($jane->getMedia('gallery'))->toHaveCount(0)
        ->and($johns->fresh())->not->toBeNull();

    Storage::disk('public')->assertMissing($a->getPath());
    Event::assertDispatchedTimes(MediaHasBeenDeleted::class, 2);
});

// Regression: `clearMediaBucket()` / `Media::clearBucket()` force-deleted rows directly, so the
// MediaHasBeenDeleted event that `media:clear` and `deleteWithFiles()` fire never fired.
it('fires the delete event when a bucket is cleared through the trait or the global clear', function (): void {
    Event::fake([MediaHasBeenDeleted::class]);
    $user = facadeUser();
    $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    MediaLibrary::add(__DIR__.'/../files/pixel.png')->toBucket('brand');

    $user->clearMediaBucket('gallery');

    expect(MediaLibrary::clearBucket('brand'))->toBe(1);
    Event::assertDispatchedTimes(MediaHasBeenDeleted::class, 2);
});

it('deletes its own media through for()', function (): void {
    $user = facadeUser();
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    MediaLibrary::for($user)->delete($media);

    expect(Media::query()->find($media->id))->toBeNull();
    Storage::disk('public')->assertMissing($media->getPath());
});

it('refuses to delete media owned by another model', function (): void {
    $jane = facadeUser('Jane');
    $john = facadeUser('John');
    $johns = $john->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    expect(fn () => MediaLibrary::for($jane)->delete($johns))
        ->toThrow(MediaDoesNotBelongToModel::class);

    expect($johns->fresh())->not->toBeNull();
    Storage::disk('public')->assertExists($johns->getPath());
});

it('refuses to delete global media through an owner handle', function (): void {
    $global = MediaLibrary::add(__DIR__.'/../files/pixel.png')->toBucket('brand');

    expect(fn () => MediaLibrary::for(facadeUser())->delete($global))
        ->toThrow(MediaDoesNotBelongToModel::class, 'does not belong to');

    expect($global->fresh())->not->toBeNull();
});

// --- flat verbs ---

it('moves, copies and relocates disks through the flat verbs', function (): void {
    Bus::fake();
    $jane = facadeUser('Jane');
    $media = MediaLibrary::add(__DIR__.'/../files/pixel.png')->toBucket('brand');

    $moved = MediaLibrary::move($media, to: $jane, bucket: 'gallery', disk: 'cold');
    expect($moved->model_type)->toBe($jane->getMorphClass())
        ->and($moved->bucket_name)->toBe('gallery')
        ->and($moved->disk)->toBe('cold');
    Storage::disk('cold')->assertExists($moved->getPath());

    MediaLibrary::moveToDisk($moved, 'hot');
    expect($moved->fresh()?->disk)->toBe('hot')
        ->and($moved->fresh()?->bucket_name)->toBe('gallery')
        ->and((string) $moved->fresh()?->model_id)->toBe((string) $jane->getKey());

    $copy = MediaLibrary::copy($moved, to: null, bucket: 'archive', disk: 'public');
    expect($copy->uuid)->not->toBe($moved->uuid)
        ->and($copy->model_type)->toBeNull()
        ->and($copy->disk)->toBe('public');
    Storage::disk('public')->assertExists($copy->getPath());
    Storage::disk('hot')->assertExists($moved->getPath());
});

it('moves only the variant files through the flat verb', function (): void {
    Bus::fake();
    $media = facadeUser()->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('photos');

    MediaLibrary::moveVariantsToDisk($media, 'cold');

    expect($media->fresh()?->variants_disk)->toBe('cold')
        ->and($media->fresh()?->disk)->toBe('public');
    Storage::disk('cold')->assertExists($media->getPath('thumb'));
});

it('replaces and deletes through the flat verbs', function (): void {
    $media = MediaLibrary::add(__DIR__.'/../files/pixel.png')->toBucket('brand');
    $uuid = $media->uuid;

    MediaLibrary::replace($media, __DIR__.'/../files/wide.png');
    expect($media->fresh()?->uuid)->toBe($uuid)
        ->and($media->fresh()?->width)->toBe(40);

    MediaLibrary::delete($media);
    expect(MediaLibrary::find($uuid))->toBeNull();
    Storage::disk('public')->assertMissing($media->getPath());
});

// --- variants($media) ---

it('lists, filters and regenerates variants through variants()', function (): void {
    Bus::fake();
    $media = facadeUser()->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('photos');
    $variants = MediaLibrary::variants($media);

    // 'thumb' renders on add; 'display' is queued (Bus faked), so it is still missing.
    expect($variants)->toBeInstanceOf(MediaVariants::class)
        ->and(collect($variants->all())->pluck('name')->all())->toBe(['thumb', 'display'])
        ->and($variants->generated())->toBe(['thumb'])
        ->and($variants->missing())->toBe(['display']);

    expect($variants->regenerate())->toBe(['display'])
        ->and($media->fresh()?->hasGeneratedVariant('display'))->toBeTrue()
        ->and($variants->regenerate())->toBe([])
        ->and($variants->regenerate(only: ['thumb'], force: true))->toBe(['thumb'])
        ->and($variants->regenerate(only: ['nope'], force: true))->toBe([]);
});

it('regenerates through the flat verb and reports nothing for global media', function (): void {
    Bus::fake();
    $media = facadeUser()->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('photos');
    $global = MediaLibrary::add(__DIR__.'/../files/wide.png')->toBucket('brand');

    expect(MediaLibrary::regenerate($media, only: ['display']))->toBe(['display'])
        ->and(MediaLibrary::regenerate($global, force: true))->toBe([])
        ->and(MediaLibrary::variants($global)->all())->toBe([]);
});

it('prunes expired drafts through the flat verb', function (): void {
    $expired = MediaLibrary::draft(__DIR__.'/../files/pixel.png')->toBucket('avatar');
    $fresh = MediaLibrary::draft(__DIR__.'/../files/pixel.png')->toBucket('avatar');
    $expired->forceFill(['draft_expires_at' => now()->subMinute()])->save();

    expect(MediaLibrary::pruneDrafts())->toBe(1)
        ->and($expired->fresh())->toBeNull()
        ->and($fresh->fresh())->not->toBeNull();
});

// --- dependency injection ---

it('serves the same api from the injected manager', function (): void {
    $manager = app(MediaLibraryManager::class);
    $user = facadeUser();

    $media = $manager->for($user)->add(__DIR__.'/../files/pixel.png')->toBucket('gallery');

    expect($manager)->toBe(MediaLibrary::getFacadeRoot())
        ->and($manager->for($user)->first('gallery')?->is($media))->toBeTrue()
        ->and($manager->find($media->uuid)?->is($media))->toBeTrue();
});

it('scopes any eloquent model, without bucket rules when it declares none', function (): void {
    $owner = new class extends Model
    {
        protected $table = 'test_users';

        protected $guarded = [];
    };
    $owner->forceFill(['name' => 'Plain'])->save();

    $media = MediaLibrary::for($owner)->addFromString('plain')->usingFileName('a.txt')->toBucket('anything');

    expect($media->model_type)->toBe($owner->getMorphClass())
        ->and(MediaLibrary::for($owner)->url('empty'))->toBe('')
        ->and(MediaLibrary::for($owner)->url('anything'))->toBe($media->getUrl());
});
