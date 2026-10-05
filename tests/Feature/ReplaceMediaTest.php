<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Actions\DeleteMediaAction;
use RoundlyConsulting\MediaLibrary\Events\MediaHasBeenReplaced;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

function replaceUser(string $name = 'Jane'): TestUser
{
    return TestUser::query()->create(['name' => $name]);
}

it('keeps id, uuid, and url while swapping the bytes', function (): void {
    Event::fake([MediaHasBeenReplaced::class]);

    $user = replaceUser();
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    $originalId = $media->id;
    $originalUuid = $media->uuid;
    $originalUrl = $media->getUrl();
    $originalChecksum = $media->checksum;

    $media->replace(__DIR__.'/../files/sunrise.png');

    expect($media->id)->toBe($originalId)
        ->and($media->uuid)->toBe($originalUuid)
        ->and($media->getUrl())->toBe($originalUrl)
        ->and($media->checksum)->not->toBe($originalChecksum)
        ->and($media->width)->toBe(75)
        ->and($media->height)->toBe(100)
        ->and($media->size)->toBe(filesize(__DIR__.'/../files/sunrise.png'));

    // The stored bytes now match the new file's checksum.
    expect($media->verifyIntegrity())->toBeTrue();

    Event::assertDispatched(MediaHasBeenReplaced::class, fn (MediaHasBeenReplaced $e): bool => $e->media->is($media));
});

it('recomputes placeholders on replace', function (): void {
    $user = replaceUser();
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    $before = $media->placeholder();

    $media->replace(__DIR__.'/../files/sunrise.png');

    expect($media->placeholder())->not->toBe($before)
        ->and($media->thumbhash())->not->toBeNull();
})->skip(fn (): bool => ! extension_loaded('imagick') && ! extension_loaded('gd'), 'No image driver available.');

it('regenerates variants on replace', function (): void {
    $user = replaceUser();
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    expect($media->hasGeneratedVariant('small'))->toBeTrue();
    $oldVariantBytes = Storage::disk('public')->get($media->getPath('small'));

    $media->replace(__DIR__.'/../files/sunrise.png');

    expect($media->fresh()?->hasGeneratedVariant('small'))->toBeTrue();
    Storage::disk('public')->assertExists($media->getPath('small'));

    // The regenerated variant reflects the new source, so its bytes differ.
    expect(Storage::disk('public')->get($media->getPath('small')))->not->toBe($oldVariantBytes);
})->skip(fn (): bool => ! extension_loaded('imagick') && ! extension_loaded('gd'), 'No image driver available.');

it('refcount-guards the old shared original on replace', function (): void {
    $user = replaceUser();

    // Two rows share one physical original via dedup.
    $a = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');
    $b = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');

    $sharedPath = $a->getPath();
    expect($b->getPath())->toBe($sharedPath);

    // Replacing B must not delete the shared original that A still references.
    $b->replace(__DIR__.'/../files/sunrise.png');

    Storage::disk('public')->assertExists($sharedPath);
    expect($a->fresh()?->verifyIntegrity())->toBeTrue()
        ->and($b->getPath())->not->toBe($sharedPath);
});

it('overwrites a sole-referrer original in place keeping the same path and url', function (): void {
    $user = replaceUser();
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');

    $oldPath = $media->getPath();
    $oldUrl = $media->getUrl();
    $oldBytes = Storage::disk('public')->get($oldPath);

    $media->replace(__DIR__.'/../files/sunrise.png');

    // file_name is stable, so the new bytes overwrite the same path and the URL is unchanged.
    expect($media->getPath())->toBe($oldPath)
        ->and($media->getUrl())->toBe($oldUrl);

    Storage::disk('public')->assertExists($oldPath);
    expect(Storage::disk('public')->get($oldPath))->not->toBe($oldBytes);

    // No stray duplicate files left behind.
    expect(Storage::disk('public')->allFiles())->toHaveCount(1);
});

it('replaces non-image media without dimensions, placeholders, or variants', function (): void {
    $user = replaceUser();
    $media = $user->addMedia(__DIR__.'/../files/note.txt')->toMediaBucket('gallery');

    expect($media->isImage())->toBeFalse();

    $media->replace(__DIR__.'/../files/note2.txt');

    expect($media->width)->toBeNull()
        ->and($media->height)->toBeNull()
        ->and($media->placeholder())->toBe([])
        ->and($media->verifyIntegrity())->toBeTrue()
        ->and($media->size)->toBe(filesize(__DIR__.'/../files/note2.txt'));

    expect(Storage::disk('public')->get($media->getPath()))
        ->toBe((string) file_get_contents(__DIR__.'/../files/note2.txt'));
});

it('stores a fresh original on replace when dedup is disabled', function (): void {
    config()->set('media.deduplicate', false);

    $user = replaceUser();

    $canonical = $user->addMedia(__DIR__.'/../files/sunrise.png')->toMediaBucket('gallery');
    $target = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');

    $target->replace(__DIR__.'/../files/sunrise.png');

    // With dedup off, the replaced target keeps its own path rather than the canonical one.
    expect($target->getPath())->not->toBe($canonical->getPath())
        ->and($target->checksum)->toBe($canonical->checksum);
});

it('reuses an existing canonical file when replacing with already-stored bytes', function (): void {
    $user = replaceUser();

    $canonical = $user->addMedia(__DIR__.'/../files/sunrise.png')->toMediaBucket('gallery');
    $target = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');

    $targetOldPath = $target->getPath();

    // Replace target's bytes with the same content the canonical row already stores.
    $target->replace(__DIR__.'/../files/sunrise.png');

    expect($target->getPath())->toBe($canonical->getPath())
        ->and($target->checksum)->toBe($canonical->checksum);

    // Target's own former original (sole referrer of those bytes) is released.
    Storage::disk('public')->assertMissing($targetOldPath);

    // Deleting the canonical row keeps the shared file alive for the replaced target.
    app(DeleteMediaAction::class)->execute($canonical);
    Storage::disk('public')->assertExists($target->getPath());
});

/*
 * Owner decision (chat review C-23): the stored name follows the bytes. Replacing a JPEG with a
 * PNG used to keep `landscape.jpg` — PNG bytes behind a .jpg name, while the `extension` column
 * said png. The file is renamed to match, so the URL changes; a replacement of the same type
 * still overwrites in place and keeps its URL.
 */
it('renames the stored file when the replacement is of another type', function (): void {
    $user = replaceUser();
    $media = $user->addMedia(__DIR__.'/../files/landscape.jpg')->toMediaBucket('gallery');
    $oldPath = $media->getPath();
    $oldUrl = $media->getUrl();

    $media->replace(__DIR__.'/../files/pixel.png');

    $fresh = Media::query()->findOrFail($media->id);

    expect($fresh->file_name)->toBe('landscape.png')
        ->and($fresh->extension)->toBe('png')
        ->and($fresh->mime_type)->toBe('image/png')
        ->and($fresh->getPath())->toEndWith('/landscape.png')
        ->and($fresh->getUrl())->not->toBe($oldUrl);

    expect(Storage::disk('public')->get($fresh->getPath()))->toBe((string) file_get_contents(__DIR__.'/../files/pixel.png'));
    Storage::disk('public')->assertMissing($oldPath);
});

it('keeps the stored name of an upper-case extension when the type stays the same', function (): void {
    $user = replaceUser();
    $media = $user->addMedia(__DIR__.'/../files/landscape.jpg')->usingFileName('PHOTO.JPG')->toMediaBucket('gallery');
    $oldPath = $media->getPath();

    $media->replace(__DIR__.'/../files/landscape.jpg');

    expect($media->file_name)->toBe('PHOTO.JPG')
        ->and($media->getPath())->toBe($oldPath);
});
