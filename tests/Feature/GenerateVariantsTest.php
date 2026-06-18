<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Actions\GenerateVariantsAction;
use RoundlyConsulting\MediaLibrary\Events\VariantHasBeenGenerated;
use RoundlyConsulting\MediaLibrary\Events\VariantsHaveBeenGenerated;
use RoundlyConsulting\MediaLibrary\Jobs\GenerateVariantsJob;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

function userWithVariants(): TestUser
{
    return TestUser::query()->create(['name' => 'Jane']);
}

it('generates sync variants when media is added', function (): void {
    $user = userWithVariants();

    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    expect($media->hasGeneratedVariant('small'))->toBeTrue();

    Storage::disk('public')->assertExists($media->getPath('small'));
});

it('writes variants to the configured variants disk', function (): void {
    Bus::fake();

    $user = userWithVariants();

    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('photos');

    // 'thumb' is sync; it must land on the bucket's variants disk ('hot'), not the original disk.
    expect($media->hasGeneratedVariant('thumb'))->toBeTrue()
        ->and($media->variants_disk)->toBe('hot');

    Storage::disk('hot')->assertExists($media->getPath('thumb'));
    Storage::disk('public')->assertMissing($media->getPath('thumb'));
});

it('produces the requested variant dimensions', function (): void {
    $user = userWithVariants();

    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    $bytes = Storage::disk('public')->get($media->getPath('small'));
    $info = getimagesizefromstring((string) $bytes);

    // 'small' is width 8, source 40x20 → 8x4 keeping ratio.
    expect($info[0] ?? 0)->toBe(8)
        ->and($info[1] ?? 0)->toBe(4);
});

it('dispatches a job for queued variants', function (): void {
    Bus::fake();

    $user = userWithVariants();

    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('photos');

    Bus::assertDispatched(GenerateVariantsJob::class, function (GenerateVariantsJob $job) use ($media): bool {
        return $job->mediaId === $media->id && $job->variantNames === ['display'];
    });
});

it('runs both sync and queued variants in one add', function (): void {
    Queue::fake();

    $user = userWithVariants();

    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('photos');

    // sync 'thumb' already generated; queued 'display' deferred.
    expect($media->hasGeneratedVariant('thumb'))->toBeTrue()
        ->and($media->hasGeneratedVariant('display'))->toBeFalse();

    Queue::assertPushed(GenerateVariantsJob::class);
});

it('queues variants when the config default is enabled', function (): void {
    config()->set('media.queue_variants_by_default', true);
    Bus::fake();

    $user = userWithVariants();

    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    expect($media->hasGeneratedVariant('small'))->toBeFalse();

    Bus::assertDispatched(GenerateVariantsJob::class);
});

it('routes queued variants to the requested queue', function (): void {
    Bus::fake();

    $user = userWithVariants();

    $user->addMedia(__DIR__.'/../files/wide.png')->onQueue('media')->toMediaBucket('photos');

    Bus::assertDispatched(GenerateVariantsJob::class, function (GenerateVariantsJob $job): bool {
        return $job->queue === 'media';
    });
});

it('fires per-variant and completion events', function (): void {
    Bus::fake();
    Event::fake([VariantHasBeenGenerated::class, VariantsHaveBeenGenerated::class]);

    $user = userWithVariants();
    $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    Event::assertDispatched(VariantHasBeenGenerated::class);
    Event::assertDispatched(VariantsHaveBeenGenerated::class);
});

it('does not generate variants for non-image media', function (): void {
    $user = userWithVariants();

    $media = $user->addMediaFromString('plain text')->usingFileName('note.txt')->toMediaBucket('covers');

    expect($media->generated_variants)->toBe([]);
});

it('does not require an image driver for media without variants', function (): void {
    $user = userWithVariants();

    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');

    expect($media->generated_variants)->toBe([]);
});

it('processes queued variants when the job runs', function (): void {
    $user = userWithVariants();

    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('photos');

    // Run the queued job manually.
    $job = new GenerateVariantsJob($media->id, ['display']);
    $job->handle(app(GenerateVariantsAction::class));

    $media->refresh();

    expect($media->hasGeneratedVariant('display'))->toBeTrue();
    Storage::disk('hot')->assertExists($media->getPath('display'));
});

it('ignores a job for missing media', function (): void {
    $job = new GenerateVariantsJob(999999, ['display']);

    $job->handle(app(GenerateVariantsAction::class));
})->throwsNoExceptions();

it('applies model-level variants targeted at a bucket', function (): void {
    $user = userWithVariants();

    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    // 'watermark' is declared in registerMediaVariants() and targets the 'covers' bucket.
    expect($media->hasGeneratedVariant('watermark'))->toBeTrue();
});
