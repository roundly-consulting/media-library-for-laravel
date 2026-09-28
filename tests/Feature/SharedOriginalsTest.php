<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Contracts\PathGenerator;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

function sharingUser(string $name = 'Alice'): TestUser
{
    return TestUser::query()->create(['name' => $name]);
}

/** A layout that keeps variants in the very directory that holds the original. */
function useFlatLayout(): void
{
    app()->bind(PathGenerator::class, fn (): PathGenerator => new class implements PathGenerator
    {
        public function getPath(Media $media): string
        {
            return $media->uuid.'/';
        }

        public function getPathForVariants(Media $media): string
        {
            return $media->uuid.'/';
        }
    });
}

it('never overwrites a shared original when one referrer is replaced', function (): void {
    $alice = sharingUser('Alice');
    $bob = sharingUser('Bob');

    $a = MediaLibrary::for($alice)->add(__DIR__.'/../files/wide.png')->toBucket('gallery');
    $b = MediaLibrary::for($bob)->add(__DIR__.'/../files/wide.png')->toBucket('gallery');
    $shared = $a->getPath();
    expect($b->getPath())->toBe($shared);

    MediaLibrary::replace($a, __DIR__.'/../files/sunrise.png');

    expect(Storage::disk('public')->get($shared))->toBe((string) file_get_contents(__DIR__.'/../files/wide.png'))
        ->and($b->fresh()?->verifyIntegrity())->toBeTrue()
        ->and($a->fresh()?->verifyIntegrity())->toBeTrue()
        ->and($a->getPath())->not->toBe($shared);
});

it('never overwrites an attached row\'s file when its source is replaced', function (): void {
    $logo = MediaLibrary::add(__DIR__.'/../files/wide.png')->toBucket('brand');
    $attached = MediaLibrary::attach($logo, bucket: 'shared');

    MediaLibrary::replace($logo, __DIR__.'/../files/sunrise.png');

    expect($logo->fresh()?->verifyIntegrity())->toBeTrue()
        ->and($attached->fresh()?->verifyIntegrity())->toBeTrue();
});

it('never overwrites a same-disk copy\'s file when its source is replaced', function (): void {
    $source = MediaLibrary::add(__DIR__.'/../files/wide.png')->toBucket('brand');
    $copy = MediaLibrary::copy($source, bucket: 'brand');

    MediaLibrary::replace($source, __DIR__.'/../files/sunrise.png');

    expect($source->fresh()?->verifyIntegrity())->toBeTrue()
        ->and($copy->fresh()?->verifyIntegrity())->toBeTrue();
});

it('keeps a shared original for a soft-deleted sharer, so its restore stays lossless', function (): void {
    $a = MediaLibrary::add(__DIR__.'/../files/wide.png')->toBucket('brand');
    $b = MediaLibrary::add(__DIR__.'/../files/wide.png')->toBucket('brand');

    $b->delete();
    MediaLibrary::delete($a);

    $restored = Media::withTrashed()->findOrFail($b->id);
    $restored->restore();

    expect($restored->verifyIntegrity())->toBeTrue();
    Storage::disk('public')->assertExists($restored->getPath());
});

it('keeps a soft-deleted canonical file when its last live sharer is force-deleted', function (): void {
    $a = MediaLibrary::add(__DIR__.'/../files/wide.png')->toBucket('brand');
    $b = MediaLibrary::add(__DIR__.'/../files/wide.png')->toBucket('brand');

    $a->delete();
    MediaLibrary::delete($b);

    $restored = Media::withTrashed()->findOrFail($a->id);
    $restored->restore();

    expect($restored->verifyIntegrity())->toBeTrue();
});

it('removes a formerly shared original once the last referrer, trashed or not, is gone', function (): void {
    $a = MediaLibrary::add(__DIR__.'/../files/wide.png')->toBucket('brand');
    $b = MediaLibrary::add(__DIR__.'/../files/wide.png')->toBucket('brand');
    $shared = $a->getPath();

    $b->delete();
    MediaLibrary::delete($a);
    Storage::disk('public')->assertExists($shared);

    Media::withTrashed()->findOrFail($b->id)->forceDelete();

    Storage::disk('public')->assertMissing($shared);
});

it('removes a sole original that only shares its checksum with a separately stored row', function (): void {
    config()->set('media.deduplicate', false);

    $a = MediaLibrary::add(__DIR__.'/../files/wide.png')->toBucket('brand');
    $b = MediaLibrary::add(__DIR__.'/../files/wide.png')->toBucket('brand');
    expect($b->getPath())->not->toBe($a->getPath());

    MediaLibrary::delete($a);

    // Same bytes, but a different stored file: nothing else references A's file.
    Storage::disk('public')->assertMissing($a->getPath());
    Storage::disk('public')->assertExists($b->getPath());
});

it('never deletes an original that shares its directory with the variants on media:clean', function (): void {
    useFlatLayout();
    $user = sharingUser();

    $media = MediaLibrary::for($user)->add(__DIR__.'/../files/note.txt')->toBucket('gallery');

    $this->artisan('media:clean')->assertSuccessful();

    Storage::disk('public')->assertExists($media->getPath());
    expect($media->fresh()?->verifyIntegrity())->toBeTrue();
});

it('never deletes a shared original that sits in a deleted media\'s variants directory', function (): void {
    useFlatLayout();

    $a = MediaLibrary::add(__DIR__.'/../files/wide.png')->toBucket('brand');
    $b = MediaLibrary::add(__DIR__.'/../files/wide.png')->toBucket('brand');
    expect($b->getPath())->toBe($a->getPath());

    MediaLibrary::delete($a);

    expect($b->fresh()?->verifyIntegrity())->toBeTrue();
});

it('tidies a deleted media\'s own directories', function (): void {
    $user = sharingUser();
    $media = MediaLibrary::for($user)->add(__DIR__.'/../files/wide.png')->toBucket('covers');

    MediaLibrary::delete($media);

    expect(Storage::disk('public')->allFiles())->toBe([])
        ->and(Storage::disk('public')->allDirectories())->toBe([]);
});
