<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Actions\BackfillVariantSizesAction;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\EdgeCaseUser;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

/*
 * `media:backfill-variant-sizes` measures the variants recorded before sizes were, from the disk
 * each one lives on, and records what it measured — chunked, repeatable and resumable.
 */

/** A media in `covers` (three variants on `public`) whose records lost their sizes. */
function legacyCovers(string $owner = 'Ada'): Media
{
    $media = MediaLibrary::for(TestUser::query()->create(['name' => $owner]))
        ->add(__DIR__.'/../files/landscape.jpg')
        ->toBucket('covers');

    return withoutVariantSizes($media);
}

/** @return array<string, int|null> */
function recordedSizes(Media $media): array
{
    $sizes = [];

    foreach (MediaLibrary::variants($media)->generated() as $name) {
        $sizes[$name] = $media->variantSize($name);
    }

    ksort($sizes);

    return $sizes;
}

it('records the size of every variant stored without one', function (): void {
    $media = legacyCovers();
    $updatedAt = $media->updated_at?->toIso8601String();
    $onDisk = variantBytesOnDisk($media);

    expect(array_filter(recordedSizes($media)))->toBe([]);

    $this->artisan('media:backfill-variant-sizes')
        ->expectsOutputToContain('Recorded 3 variant size(s) ('.array_sum($onDisk).' bytes) on 1 media.')
        ->expectsOutputToContain("Checked media through #{$media->id}.")
        ->assertSuccessful();

    $fresh = $media->fresh();

    expect(recordedSizes($fresh))->toBe($onDisk)
        ->and($fresh?->updated_at?->toIso8601String())->toBe($updatedAt)
        ->and($fresh?->storedSize())->toBe(filesize(__DIR__.'/../files/landscape.jpg') + array_sum($onDisk));
});

it('records nothing on a dry run, but reports what it would', function (): void {
    $media = legacyCovers();
    $onDisk = variantBytesOnDisk($media);

    $this->artisan('media:backfill-variant-sizes', ['--dry-run' => true])
        ->expectsOutputToContain('Would record 3 variant size(s) ('.array_sum($onDisk).' bytes) on 1 media.')
        ->assertSuccessful();

    expect(array_filter(recordedSizes($media->fresh())))->toBe([]);
});

it('can run again at any point, recording only what is still missing', function (): void {
    $media = withVariantRecord(legacyCovers(), 'small', ['size' => 11]);

    $this->artisan('media:backfill-variant-sizes')
        ->expectsOutputToContain('Recorded 2 variant size(s)')
        ->assertSuccessful();

    $this->artisan('media:backfill-variant-sizes')
        ->expectsOutputToContain('Recorded 0 variant size(s) (0 bytes) on 0 media.')
        ->assertSuccessful();

    expect($media->fresh()?->variantSize('small'))->toBe(11);
});

it('resumes after a given media id', function (): void {
    $first = legacyCovers('Ada');
    $second = legacyCovers('Bea');

    $this->artisan('media:backfill-variant-sizes', ['--after' => (string) $first->id])
        ->expectsOutputToContain('on 1 media.')
        ->assertSuccessful();

    expect(array_filter(recordedSizes($first->fresh())))->toBe([])
        ->and(recordedSizes($second->fresh()))->toBe(variantBytesOnDisk($second));
});

it('reads the media in chunks of the given size', function (): void {
    $first = legacyCovers('Ada');
    $second = legacyCovers('Bea');

    $this->artisan('media:backfill-variant-sizes', ['--chunk' => '1'])
        ->expectsOutputToContain("Checked media through #{$first->id}.")
        ->expectsOutputToContain("Checked media through #{$second->id}.")
        ->expectsOutputToContain('Recorded 6 variant size(s)')
        ->assertSuccessful();
});

it('limits the run to the listed ids or one model type', function (): void {
    $first = legacyCovers('Ada');
    $second = legacyCovers('Bea');
    $other = withoutVariantSizes(
        MediaLibrary::for(EdgeCaseUser::query()->create(['name' => 'Cid']))->add(__DIR__.'/../files/wide.png')->toBucket('thumbs'),
    );

    $this->artisan('media:backfill-variant-sizes', ['--ids' => (string) $second->id])->assertSuccessful();

    expect(array_filter(recordedSizes($first->fresh())))->toBe([])
        ->and(recordedSizes($second->fresh()))->toBe(variantBytesOnDisk($second));

    $this->artisan('media:backfill-variant-sizes', ['model' => TestUser::class])->assertSuccessful();

    expect(recordedSizes($first->fresh()))->toBe(variantBytesOnDisk($first))
        ->and(recordedSizes($other->fresh()))->toBe(['thumb' => null]);
});

it('measures variants on the disk each one lives on', function (): void {
    $media = withoutVariantSizes(
        MediaLibrary::for(TestUser::query()->create(['name' => 'Ada']))->add(__DIR__.'/../files/wide.png')->toBucket('stored'),
    );

    $this->artisan('media:backfill-variant-sizes')->assertSuccessful();

    expect($media->fresh()?->variantSize('thumb'))->toBe(Storage::disk('s3')->size($media->getPath('thumb')));
});

it('includes soft-deleted media, whose files are still stored', function (): void {
    $media = legacyCovers();
    $media->delete();

    $this->artisan('media:backfill-variant-sizes')->expectsOutputToContain('on 1 media.')->assertSuccessful();

    expect(recordedSizes(Media::withTrashed()->find($media->id)))->toBe(variantBytesOnDisk($media));
});

it('reports a variant it cannot measure and leaves its size unknown', function (): void {
    $media = legacyCovers();
    $missing = $media->getPath('small');
    Storage::disk('public')->delete($missing);

    $this->artisan('media:backfill-variant-sizes')
        ->expectsOutputToContain("Unreadable: media #{$media->id} variant 'small' at public:{$missing}")
        ->expectsOutputToContain('Recorded 2 variant size(s)')
        ->expectsOutputToContain('1 variant file(s) could not be measured and keep an unknown size.')
        ->assertSuccessful();

    expect($media->fresh()?->variantSize('small'))->toBeNull()
        ->and($media->fresh()?->variantSize('keepformat'))->toBe(Storage::disk('public')->size($media->getPath('keepformat')));
});

it('refuses an option that is not a whole number', function (array $options, string $message): void {
    $media = legacyCovers();

    $this->artisan('media:backfill-variant-sizes', $options)
        ->expectsOutputToContain($message)
        ->assertExitCode(2);

    expect(array_filter(recordedSizes($media->fresh())))->toBe([]);
})->with([
    'zero chunk' => [['--chunk' => '0'], 'The --chunk option must be a whole number of at least 1.'],
    'word chunk' => [['--chunk' => 'many'], 'The --chunk option must be a whole number of at least 1.'],
    'negative after' => [['--after' => '-1'], 'The --after option must be a whole number of at least 0.'],
    'word after' => [['--after' => 'x'], 'The --after option must be a whole number of at least 0.'],
]);

it('skips media whose variants were never recorded', function (): void {
    Media::factory()->create(['generated_variants' => null]);
    Media::factory()->create(['generated_variants' => []]);

    $this->artisan('media:backfill-variant-sizes')
        ->expectsOutputToContain('Recorded 0 variant size(s) (0 bytes) on 0 media.')
        ->assertSuccessful();
});

// --- the action, against a row that changed while the disks were asked ---

it('writes no size to a record a re-render replaced meanwhile', function (): void {
    $stale = legacyCovers();
    withVariantRecord(withVariantRecord($stale, 'small', ['file_name' => 'small-new.jpg']), 'keepformat', ['size' => 5]);

    $result = app(BackfillVariantSizesAction::class)->execute($stale);

    $fresh = $stale->fresh();

    expect($result->recorded)->toBe(['watermark'])
        ->and($fresh?->generatedVariant('small')?->fileName)->toBe('small-new.jpg')
        ->and($fresh?->variantSize('small'))->toBeNull()
        ->and($fresh?->variantSize('keepformat'))->toBe(5)
        ->and($fresh?->variantSize('watermark'))->toBe($result->bytes)
        ->and($stale->variantSize('watermark'))->toBe($result->bytes);
});

it('writes nothing for a row deleted while the disks were asked', function (): void {
    $stale = legacyCovers();
    Media::query()->whereKey($stale->getKey())->toBase()->delete();

    $result = app(BackfillVariantSizesAction::class)->execute($stale);

    expect($result->recorded)->toBe([])
        ->and($result->bytes)->toBe(0)
        ->and($result->unreadable)->toBe([]);
});

it('skips records that already carry a size without asking the disk', function (): void {
    $media = MediaLibrary::for(TestUser::query()->create(['name' => 'Ada']))
        ->add(__DIR__.'/../files/landscape.jpg')
        ->toBucket('covers');
    Storage::disk('public')->deleteDirectory(rtrim($media->getPathForVariantsDirectory(), '/'));

    $result = app(BackfillVariantSizesAction::class)->execute($media);

    expect($result->recorded)->toBe([])
        ->and($result->unreadable)->toBe([]);
});

it('writes nothing when every measured record was re-rendered meanwhile', function (): void {
    $stale = legacyCovers();
    $sized = $stale->generated_variants ?? [];

    foreach (array_keys($sized) as $name) {
        $sized[$name]['size'] = 1;
    }

    Media::query()->whereKey($stale->getKey())->toBase()->update(['generated_variants' => json_encode($sized)]);

    $result = app(BackfillVariantSizesAction::class)->execute($stale);

    expect($result->recorded)->toBe([])
        ->and(recordedSizes($stale->fresh()))->toBe(['keepformat' => 1, 'small' => 1, 'watermark' => 1]);
});
