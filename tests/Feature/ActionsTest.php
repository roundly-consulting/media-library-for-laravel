<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Actions\MoveMediaVariantsAction;
use RoundlyConsulting\MediaLibrary\Actions\PruneDraftsAction;
use RoundlyConsulting\MediaLibrary\Actions\RegenerateVariantsAction;
use RoundlyConsulting\MediaLibrary\Events\MediaHasBeenMoved;
use RoundlyConsulting\MediaLibrary\Exceptions\DiskDoesNotExist;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

function actionsUser(): TestUser
{
    return TestUser::query()->create(['name' => 'Jane']);
}

it('regenerates only missing variants unless forced, narrowed by only', function (): void {
    Bus::fake();
    $media = actionsUser()->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('photos');
    $action = app(RegenerateVariantsAction::class);

    expect($action->execute($media, only: ['thumb']))->toBe([])
        ->and($action->execute($media))->toBe(['display'])
        ->and($action->execute($media, force: true))->toBe(['thumb', 'display']);

    Storage::disk('hot')->assertExists($media->getPath('display'));
});

it('moves only the variant files and records the variants disk', function (): void {
    Bus::fake();
    Event::fake([MediaHasBeenMoved::class]);
    $media = actionsUser()->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('photos');
    $action = app(MoveMediaVariantsAction::class);

    $action->execute($media, 'cold');
    expect($media->fresh()?->variants_disk)->toBe('cold');
    Storage::disk('cold')->assertExists($media->getPath('thumb'));
    Storage::disk('hot')->assertMissing($media->getPath('thumb'));

    // Back onto the original's own disk: recorded as null (= "same as the original").
    $action->execute($media, 'public');
    expect($media->fresh()?->variants_disk)->toBeNull();
    Storage::disk('public')->assertExists($media->getPath('thumb'));

    Event::assertDispatchedTimes(MediaHasBeenMoved::class, 2);
    expect(fn () => $action->execute($media, 'nowhere'))->toThrow(DiskDoesNotExist::class);
});

it('prunes only expired, unbound drafts', function (): void {
    Media::factory()->create(['draft_token' => 'expired', 'draft_expires_at' => now()->subMinute()]);
    $unexpired = Media::factory()->create(['draft_token' => 'fresh', 'draft_expires_at' => now()->addHour()]);
    $bound = Media::factory()->create(['draft_token' => null, 'draft_expires_at' => now()->subMinute()]);

    expect(app(PruneDraftsAction::class)->execute())->toBe(1)
        ->and(Media::query()->pluck('id')->sort()->values()->all())->toBe([$unexpired->id, $bound->id]);
});

// Regression: pruning iterated with `each()`, which pages by OFFSET. Deleting the first page
// shifted the rest down, so page two started past them — every other chunk of 1000 survived.
it('prunes more expired drafts than one chunk holds', function (): void {
    Media::factory()->count(1001)->create(['draft_token' => 'x', 'draft_expires_at' => now()->subMinute()]);

    expect(app(PruneDraftsAction::class)->execute())->toBe(1001)
        ->and(Media::query()->count())->toBe(0);
});

// Regression: `media:clear` had the same offset-paging skip as the draft prune.
it('clears a bucket larger than one chunk from the command', function (): void {
    Media::factory()->count(1001)->inBucket('bulk')->create();

    $this->artisan('media:clear', ['model' => '', 'bucket' => 'bulk'])
        ->expectsOutput("Cleared 1001 media from bucket 'bulk'.")
        ->assertSuccessful();

    expect(Media::query()->count())->toBe(0);
});
