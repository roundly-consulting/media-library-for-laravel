<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Actions\MeasureStorageUsageAction;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\StorageUsage;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

/*
 * `MediaLibrary::usage()` counts what the disks physically hold: a deduplicated original once,
 * however many rows point at it, plus every row's own variants.
 */

function usageUser(string $name = 'Ada'): TestUser
{
    return TestUser::query()->create(['name' => $name]);
}

/** The bytes every file on a disk holds. */
function bytesOnDisk(string $disk): int
{
    $storage = Storage::disk($disk);

    return array_sum(array_map(static fn (string $file): int => $storage->size($file), $storage->allFiles()));
}

it('counts a deduplicated original once, however many rows share it', function (): void {
    $bytes = filesize(__DIR__.'/../files/pixel.png');

    $first = MediaLibrary::add(__DIR__.'/../files/pixel.png')->toBucket('library');
    $second = MediaLibrary::add(__DIR__.'/../files/pixel.png')->toBucket('library');
    MediaLibrary::attach($first, bucket: 'shared');

    $usage = MediaLibrary::usage();

    expect($second->getPath())->toBe($first->getPath())
        ->and($usage)->toBeInstanceOf(StorageUsage::class)
        ->and($usage->originalBytes)->toBe($bytes)
        ->and($usage->referencedOriginalBytes)->toBe(3 * $bytes)
        ->and($usage->savedBytes())->toBe(2 * $bytes)
        ->and($usage->variantBytes)->toBe(0)
        ->and($usage->totalBytes())->toBe(bytesOnDisk('public'));
});

it('matches the bytes on disk when deduplicated originals carry their own variants', function (): void {
    MediaLibrary::for(usageUser('Ada'))->add(__DIR__.'/../files/landscape.jpg')->toBucket('covers');
    MediaLibrary::for(usageUser('Bea'))->add(__DIR__.'/../files/landscape.jpg')->toBucket('covers');
    MediaLibrary::for(usageUser('Cid'))->add(__DIR__.'/../files/sunrise.png')->toBucket('covers');

    $usage = MediaLibrary::usage();
    $rows = Media::query()->get();

    expect($rows)->toHaveCount(3)
        ->and($usage->totalBytes())->toBe(bytesOnDisk('public'))
        ->and($usage->originalBytes)->toBe(filesize(__DIR__.'/../files/landscape.jpg') + filesize(__DIR__.'/../files/sunrise.png'))
        ->and($usage->variantBytes)->toBe($rows->sum(fn (Media $media): int => $media->variantsSize()))
        ->and($usage->referencedOriginalBytes + $usage->variantBytes)->toBe($rows->sum(fn (Media $media): int => $media->storedSize()));
});

it('counts the originals on every disk the media uses', function (): void {
    MediaLibrary::add(__DIR__.'/../files/pixel.png')->toBucket('library');
    MediaLibrary::add(__DIR__.'/../files/pixel.png')->useDisk('cold')->toBucket('library');

    expect(MediaLibrary::usage()->originalBytes)->toBe(2 * filesize(__DIR__.'/../files/pixel.png'));
});

it('scopes the count to the query it is given, counting a shared file once per scope', function (): void {
    $ada = usageUser('Ada');
    $bea = usageUser('Bea');
    $bytes = filesize(__DIR__.'/../files/landscape.jpg');

    $first = MediaLibrary::for($ada)->add(__DIR__.'/../files/landscape.jpg')->toBucket('gallery');
    MediaLibrary::attach($first, $ada, 'gallery');
    MediaLibrary::for($bea)->add(__DIR__.'/../files/landscape.jpg')->toBucket('gallery');

    $adas = MediaLibrary::usage(Media::query()->forModel($ada));
    $beas = MediaLibrary::usage(Media::query()->forModel($bea));

    expect($adas->originalBytes)->toBe($bytes)
        ->and($adas->referencedOriginalBytes)->toBe(2 * $bytes)
        ->and($beas->originalBytes)->toBe($bytes)
        ->and(MediaLibrary::usage()->originalBytes)->toBe($bytes);
});

it('counts every row without a stored path on its own', function (): void {
    Media::factory()->create(['path' => null, 'size' => 10]);
    Media::factory()->create(['path' => null, 'size' => 20]);
    Media::factory()->create(['path' => 'shared/a.jpg', 'size' => 5]);
    Media::factory()->create(['path' => 'shared/a.jpg', 'size' => 5]);
    Media::factory()->create(['path' => 'shared/a.jpg', 'size' => 5, 'disk' => 'cold']);

    $usage = MediaLibrary::usage();

    expect($usage->originalBytes)->toBe(10 + 20 + 5 + 5)
        ->and($usage->referencedOriginalBytes)->toBe(45);
});

it('includes soft-deleted media by default, whose files are still stored', function (): void {
    $kept = Media::factory()->create(['path' => 'a/a.jpg', 'size' => 100]);
    $trashed = Media::factory()->create(['path' => 'b/b.jpg', 'size' => 50, 'generated_variants' => [
        'thumb' => ['file_name' => 'thumb.webp', 'format' => 'webp', 'disk' => 'public', 'size' => 7],
    ]]);
    $trashed->delete();

    expect(MediaLibrary::usage()->totalBytes())->toBe(157)
        ->and(MediaLibrary::usage(Media::query())->totalBytes())->toBe(100)
        ->and($kept->trashed())->toBeFalse();
});

it('sums only the variant sizes it knows', function (): void {
    Media::factory()->create(['size' => 100, 'generated_variants' => [
        'thumb' => ['file_name' => 'thumb.webp', 'format' => 'webp', 'disk' => 'public', 'size' => 30],
        'legacy' => ['file_name' => 'legacy.webp', 'format' => 'webp', 'disk' => 'public'],
    ]]);
    Media::factory()->create(['size' => 100, 'generated_variants' => null]);

    expect(MediaLibrary::usage()->variantBytes)->toBe(30);
});

it('reads every row across several chunks', function (): void {
    $records = ['thumb' => ['file_name' => 'thumb.webp', 'format' => 'webp', 'disk' => 'public', 'size' => 3]];
    Media::factory()->count(1005)->create(['size' => 1, 'generated_variants' => $records]);

    expect(MediaLibrary::usage()->variantBytes)->toBe(3015);
});

it('ignores the ordering of the query it is given', function (): void {
    MediaLibrary::add(__DIR__.'/../files/pixel.png')->toBucket('library');
    MediaLibrary::add(__DIR__.'/../files/pixel.png')->toBucket('library');

    expect(MediaLibrary::usage(MediaLibrary::bucket('library'))->originalBytes)->toBe(filesize(__DIR__.'/../files/pixel.png'));
});

it('measures nothing for an empty selection', function (): void {
    $usage = MediaLibrary::usage();

    expect([$usage->originalBytes, $usage->variantBytes, $usage->referencedOriginalBytes, $usage->totalBytes(), $usage->savedBytes()])
        ->toBe([0, 0, 0, 0, 0]);
});

it('serves the same measurement from the injected manager and the action', function (): void {
    MediaLibrary::add(__DIR__.'/../files/pixel.png')->toBucket('library');

    $facade = MediaLibrary::usage();

    expect(app(MediaLibraryManager::class)->usage())->toEqual($facade)
        ->and(app(MeasureStorageUsageAction::class)->execute(Media::query()->withTrashed()))->toEqual($facade);
});

it('keeps usage() real under the fake, reading the stored rows', function (): void {
    Media::factory()->create(['path' => 'a/a.jpg', 'size' => 42]);

    MediaLibrary::fake();

    expect(MediaLibrary::usage()->originalBytes)->toBe(42);
});
