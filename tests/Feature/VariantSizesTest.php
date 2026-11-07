<?php

declare(strict_types=1);

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Events\VariantHasBeenGenerated;
use RoundlyConsulting\MediaLibrary\Events\VariantsHaveBeenDeleted;
use RoundlyConsulting\MediaLibrary\Events\VariantsHaveBeenGenerated;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

/*
 * Every generated variant records the bytes written, so storage can be metered from the row
 * alone; every event that changes stored variant bytes carries the numbers.
 */

function sizesUser(string $name = 'Ada'): TestUser
{
    return TestUser::query()->create(['name' => $name]);
}

/** variant => size, from a list of per-variant generation events. */
function sizesByVariant(iterable $events): array
{
    $sizes = [];

    foreach ($events as $event) {
        $sizes[$event->variant] = $event->size;
    }

    ksort($sizes);

    return $sizes;
}

// --- recording ---

it('records the bytes written for each variant on a local disk', function (): void {
    Bus::fake();

    $media = MediaLibrary::for(sizesUser())->add(__DIR__.'/../files/wide.png')->toBucket('photos')->fresh();

    $onDisk = Storage::disk('hot')->size($media->getPath('thumb'));

    expect($media->generated_variants['thumb']['size'])->toBe($onDisk)
        ->and($media->variantSize('thumb'))->toBe($onDisk)
        ->and($media->variantSize('display'))->toBeNull()
        ->and($media->variantsSize())->toBe($onDisk);
});

it('records the bytes written on a faked s3 disk', function (): void {
    $media = MediaLibrary::for(sizesUser())->add(__DIR__.'/../files/wide.png')->toBucket('stored')->fresh();

    expect($media->diskFor('thumb'))->toBe('s3')
        ->and($media->variantSize('thumb'))->toBe(Storage::disk('s3')->size($media->getPath('thumb')));
});

it('records the size on a real S3 adapter without asking the store for it', function (): void {
    $sent = new ArrayObject;
    $handler = new MockHandler;

    // Exactly one answer per variant: any other request — a HEAD for the size included — finds
    // the queue empty and fails.
    foreach (range(1, 3) as $ignored) {
        $handler->append(static function (CommandInterface $command) use ($sent): Result {
            $sent[] = [$command->getName(), strlen((string) $command['Body'])];

            return new Result([]);
        });
    }

    config()->set('filesystems.disks.variants-s3', [
        'driver' => 's3',
        'key' => 'AKIATEST',
        'secret' => 'secret',
        'region' => 'eu-central-1',
        'bucket' => 'media',
        'handler' => $handler,
    ]);
    Storage::forgetDisk('variants-s3');
    $generated = collectEvents(VariantHasBeenGenerated::class);

    $media = MediaLibrary::for(sizesUser())->add(__DIR__.'/../files/landscape.jpg')
        ->storingVariantsOnDisk('variants-s3')
        ->toBucket('covers');

    $written = array_column($sent->getArrayCopy(), 1);

    expect(array_column($sent->getArrayCopy(), 0))->toBe(['PutObject', 'PutObject', 'PutObject'])
        ->and(count($handler))->toBe(0)
        ->and(array_map(static fn (VariantHasBeenGenerated $event): int => $event->size, $generated->getArrayCopy()))->toBe($written)
        ->and($media->fresh()?->variantsSize())->toBe(array_sum($written))
        ->and($media->fresh()?->storedSize())->toBe(filesize(__DIR__.'/../files/landscape.jpg') + array_sum($written));
});

it('stores exactly the bytes on disk after adding an image with three variants, and the events report the same numbers', function (): void {
    $generated = collectEvents(VariantHasBeenGenerated::class);
    $runs = collectEvents(VariantsHaveBeenGenerated::class);

    $media = MediaLibrary::for(sizesUser())->add(__DIR__.'/../files/landscape.jpg')->toBucket('covers')->fresh();

    $variants = variantBytesOnDisk($media);
    $original = Storage::disk('public')->size($media->getPath());

    expect($variants)->toHaveKeys(['small', 'keepformat', 'watermark'])->toHaveCount(3)
        ->and($media->storedSize())->toBe($original + array_sum($variants))
        ->and($media->variantsSize())->toBe(array_sum($variants))
        ->and(sizesByVariant($generated))->toBe($variants)
        ->and(array_sum(array_map(static fn (VariantHasBeenGenerated $event): int => $event->freedBytes, $generated->getArrayCopy())))->toBe(0)
        ->and($runs)->toHaveCount(1)
        ->and($runs[0]->size)->toBe(array_sum($variants))
        ->and($runs[0]->freedBytes)->toBe(0)
        ->and($runs[0]->delta())->toBe(array_sum($variants))
        ->and(MediaLibrary::usage()->totalBytes())->toBe($media->storedSize());

    foreach ($variants as $name => $bytes) {
        expect($media->variantSize($name))->toBe($bytes);
    }
});

it('records the size of a variant rendered by the queued job', function (): void {
    $media = MediaLibrary::for(sizesUser())->add(__DIR__.'/../files/wide.png')->toBucket('photos')->fresh();

    expect($media->variantSize('display'))->toBe(Storage::disk('hot')->size($media->getPath('display')));
});

// --- old records ---

it('reads a record stored without a size as unknown, and counts only known sizes', function (): void {
    $media = Media::factory()->create([
        'size' => 1000,
        'generated_variants' => [
            'thumb' => ['file_name' => 'thumb.webp', 'format' => 'webp', 'disk' => 'public', 'size' => 120],
            'legacy' => ['file_name' => 'legacy.webp', 'format' => 'webp', 'disk' => 'public'],
        ],
    ])->fresh();

    expect($media->variantSize('thumb'))->toBe(120)
        ->and($media->variantSize('legacy'))->toBeNull()
        ->and($media->variantSize('never'))->toBeNull()
        ->and($media->variantSize(''))->toBeNull()
        ->and($media->variantsSize())->toBe(120)
        ->and($media->storedSize())->toBe(1120);
});

it('has no variant bytes before anything was generated', function (): void {
    $media = Media::factory()->create(['size' => 64, 'generated_variants' => null]);

    expect($media->variantsSize())->toBe(0)
        ->and($media->storedSize())->toBe(64);
});

it('keeps an old record without a size key when its variants move disks', function (): void {
    $media = withoutVariantSizes(MediaLibrary::for(sizesUser())->add(__DIR__.'/../files/landscape.jpg')->toBucket('covers'));

    MediaLibrary::moveVariantsToDisk($media, 'cold');

    foreach ($media->fresh()?->generated_variants ?? [] as $record) {
        expect($record)->not->toHaveKey('size')
            ->and($record['disk'])->toBe('cold');
    }
});

it('carries the recorded sizes along when variants move disks', function (): void {
    $media = MediaLibrary::for(sizesUser())->add(__DIR__.'/../files/landscape.jpg')->toBucket('covers');
    $before = variantBytesOnDisk($media);

    MediaLibrary::moveVariantsToDisk($media, 'cold');
    $moved = $media->fresh();

    expect(variantBytesOnDisk($moved))->toBe($before);

    foreach ($before as $name => $bytes) {
        expect($moved?->variantSize($name))->toBe($bytes);
    }
});

it('copies the recorded sizes with the variant files', function (): void {
    $media = MediaLibrary::for(sizesUser())->add(__DIR__.'/../files/landscape.jpg')->toBucket('covers');

    $copy = MediaLibrary::copy($media, sizesUser('Bea'), 'covers')->fresh();

    expect($copy?->variantsSize())->toBe($media->fresh()?->variantsSize())
        ->and(array_sum(variantBytesOnDisk($copy)))->toBe($copy?->variantsSize());
});

// --- regenerate ---

it('reports the replaced bytes when a variant is re-rendered in place', function (): void {
    $media = withVariantRecord(
        MediaLibrary::for(sizesUser())->add(__DIR__.'/../files/landscape.jpg')->toBucket('covers'),
        'small',
        ['size' => 999],
    );
    $generated = collectEvents(VariantHasBeenGenerated::class);
    $runs = collectEvents(VariantsHaveBeenGenerated::class);

    MediaLibrary::regenerate($media, ['small'], force: true);

    $written = Storage::disk('public')->size($media->getPath('small'));

    expect($generated)->toHaveCount(1)
        ->and($generated[0]->size)->toBe($written)
        ->and($generated[0]->freedBytes)->toBe(999)
        ->and($generated[0]->delta())->toBe($written - 999)
        ->and($runs[0]->variants)->toBe(['small'])
        ->and($runs[0]->size)->toBe($written)
        ->and($runs[0]->freedBytes)->toBe(999)
        ->and($media->fresh()?->variantSize('small'))->toBe($written);
});

it('reports the bytes of a previous file the re-render removed', function (): void {
    $media = MediaLibrary::for(sizesUser())->add(__DIR__.'/../files/landscape.jpg')->toBucket('covers');
    $old = $media->getPathForVariantsDirectory().'small-old.jpg';
    Storage::disk('public')->put($old, str_repeat('x', 321));
    $media = withVariantRecord($media, 'small', ['file_name' => 'small-old.jpg', 'size' => 321]);
    $generated = collectEvents(VariantHasBeenGenerated::class);

    MediaLibrary::regenerate($media, ['small'], force: true);

    Storage::disk('public')->assertMissing($old);
    expect($generated[0]->freedBytes)->toBe(321)
        ->and($generated[0]->size)->toBe(Storage::disk('public')->size($media->getPath('small')));
});

it('frees nothing it never counted when an old record is re-rendered', function (): void {
    $media = withoutVariantSizes(MediaLibrary::for(sizesUser())->add(__DIR__.'/../files/landscape.jpg')->toBucket('covers'));
    $runs = collectEvents(VariantsHaveBeenGenerated::class);

    MediaLibrary::regenerate($media, force: true);

    $onDisk = variantBytesOnDisk($media->fresh());

    expect($runs[0]->freedBytes)->toBe(0)
        ->and($runs[0]->size)->toBe(array_sum($onDisk))
        ->and($media->fresh()?->variantsSize())->toBe(array_sum($onDisk));
});

it('keeps the events a host constructs by hand without sizes working', function (): void {
    $media = Media::factory()->make();

    $one = new VariantHasBeenGenerated($media, 'thumb');
    $run = new VariantsHaveBeenGenerated($media, ['thumb']);

    expect([$one->size, $one->freedBytes, $one->delta(), $run->size, $run->freedBytes, $run->delta()])
        ->toBe([0, 0, 0, 0, 0, 0]);
});

// --- delete ---

it('reports the freed variant bytes when media is deleted', function (): void {
    $media = MediaLibrary::for(sizesUser())->add(__DIR__.'/../files/landscape.jpg')->toBucket('covers')->fresh();
    $bytes = $media->variantsSize();
    $deleted = collectEvents(VariantsHaveBeenDeleted::class);

    MediaLibrary::delete($media);

    $variants = $deleted[0]->variants;
    sort($variants);

    expect($deleted)->toHaveCount(1)
        ->and($variants)->toBe(['keepformat', 'small', 'watermark'])
        ->and($deleted[0]->freedBytes)->toBe($bytes)
        ->and($deleted[0]->media->is($media))->toBeTrue()
        ->and(Storage::disk('public')->allFiles())->toBe([]);
});

it('reports the freed variant bytes on a plain force delete as well', function (): void {
    $media = MediaLibrary::for(sizesUser())->add(__DIR__.'/../files/landscape.jpg')->toBucket('covers')->fresh();
    $deleted = collectEvents(VariantsHaveBeenDeleted::class);

    $media->forceDelete();

    expect($deleted[0]->freedBytes)->toBe($media->variantsSize());
});

it('counts an old record without a size as zero freed bytes', function (): void {
    $media = withVariantRecord(
        MediaLibrary::for(sizesUser())->add(__DIR__.'/../files/landscape.jpg')->toBucket('covers'),
        'small',
        ['size' => null],
    );
    $deleted = collectEvents(VariantsHaveBeenDeleted::class);

    MediaLibrary::delete($media);

    expect($deleted[0]->freedBytes)->toBe($media->variantSize('keepformat') + $media->variantSize('watermark'));
});

it('announces no variants for media that had none', function (): void {
    $media = MediaLibrary::add(__DIR__.'/../files/note.txt')->toBucket('library');
    $deleted = collectEvents(VariantsHaveBeenDeleted::class);

    MediaLibrary::delete($media);

    expect($deleted)->toHaveCount(0);
});

it('reports the freed bytes only once the deleting transaction commits', function (): void {
    $media = MediaLibrary::for(sizesUser())->add(__DIR__.'/../files/landscape.jpg')->toBucket('covers')->fresh();
    $deleted = collectEvents(VariantsHaveBeenDeleted::class);

    DB::transaction(function () use ($media, $deleted): void {
        MediaLibrary::delete($media);

        expect($deleted)->toHaveCount(0);
    });

    expect($deleted)->toHaveCount(1);
});

it('reports nothing freed when the deleting transaction rolls back', function (): void {
    $media = MediaLibrary::for(sizesUser())->add(__DIR__.'/../files/landscape.jpg')->toBucket('covers')->fresh();
    $deleted = collectEvents(VariantsHaveBeenDeleted::class);

    try {
        DB::transaction(function () use ($media): void {
            MediaLibrary::delete($media);

            throw new RuntimeException('roll back');
        });
    } catch (RuntimeException) {
    }

    expect($deleted)->toHaveCount(0)
        ->and(array_sum(variantBytesOnDisk($media)))->toBe($media->variantsSize());
});

// --- replace and re-home ---

it('reports the old variant bytes freed and the new ones written when the original is replaced', function (): void {
    $media = MediaLibrary::for(sizesUser())->add(__DIR__.'/../files/landscape.jpg')->toBucket('covers')->fresh();
    $old = $media->variantsSize();
    $log = new ArrayObject;
    Event::listen([VariantsHaveBeenDeleted::class, VariantsHaveBeenGenerated::class], static function (object $event) use ($log): void {
        $log[] = $event;
    });

    MediaLibrary::replace($media, __DIR__.'/../files/sunrise.png');

    $new = variantBytesOnDisk($media->fresh());

    expect($log)->toHaveCount(2)
        ->and($log[0])->toBeInstanceOf(VariantsHaveBeenDeleted::class)
        ->and($log[0]->freedBytes)->toBe($old)
        ->and($log[1])->toBeInstanceOf(VariantsHaveBeenGenerated::class)
        ->and($log[1]->size)->toBe(array_sum($new))
        ->and($log[1]->freedBytes)->toBe(0)
        ->and($media->fresh()?->storedSize())->toBe(filesize(__DIR__.'/../files/sunrise.png') + array_sum($new));
});

it('reports the bytes of variants dropped when media moves into a bucket without them', function (): void {
    $user = sizesUser();
    $media = MediaLibrary::for($user)->add(__DIR__.'/../files/landscape.jpg')->toBucket('covers')->fresh();
    $bytes = $media->variantsSize();
    $deleted = collectEvents(VariantsHaveBeenDeleted::class);

    MediaLibrary::move($media, $user, 'gallery');

    $variants = $deleted[0]->variants;
    sort($variants);

    expect($variants)->toBe(['keepformat', 'small', 'watermark'])
        ->and($deleted[0]->freedBytes)->toBe($bytes)
        ->and($media->fresh()?->variantsSize())->toBe(0)
        ->and(Storage::disk('public')->allFiles())->toBe([$media->getPath()]);
});

it('reports dropped variant bytes only once the moving transaction commits', function (): void {
    $user = sizesUser();
    $media = MediaLibrary::for($user)->add(__DIR__.'/../files/landscape.jpg')->toBucket('covers')->fresh();
    $deleted = collectEvents(VariantsHaveBeenDeleted::class);

    DB::transaction(function () use ($media, $user, $deleted): void {
        MediaLibrary::move($media, $user, 'gallery');

        expect($deleted)->toHaveCount(0);
    });

    expect($deleted)->toHaveCount(1);
});

it('neither deletes nor counts a dropped variant file another row stores as its original', function (): void {
    $user = sizesUser();
    $media = MediaLibrary::for($user)->add(__DIR__.'/../files/landscape.jpg')->toBucket('covers')->fresh();
    $kept = $media->getPath('small');
    Media::factory()->create(['disk' => 'public', 'path' => $kept, 'size' => 1]);
    $released = $media->variantSize('keepformat') + $media->variantSize('watermark');
    $deleted = collectEvents(VariantsHaveBeenDeleted::class);

    MediaLibrary::move($media, $user, 'gallery');

    $variants = $deleted[0]->variants;
    sort($variants);

    Storage::disk('public')->assertExists($kept);
    expect($variants)->toBe(['keepformat', 'watermark'])
        ->and($deleted[0]->freedBytes)->toBe($released);
});
