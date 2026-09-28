<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Events\DraftMediaHasBeenBound;
use RoundlyConsulting\MediaLibrary\Exceptions\DraftMediaExpired;
use RoundlyConsulting\MediaLibrary\Exceptions\DraftMediaNotFound;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Models\Media as MediaModel;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function draftUser(string $name = 'Jane'): TestUser
{
    return TestUser::query()->create(['name' => $name]);
}

it('stores a draft via the facade with a token, null owner, and TTL expiry', function (): void {
    CarbonImmutable::setTestNow('2026-06-18 12:00:00');

    $draft = MediaLibrary::draft(__DIR__.'/../files/pixel.png')->toBucket('avatar');

    expect($draft->draft_token)->not->toBeNull()
        ->and($draft->model_type)->toBeNull()
        ->and($draft->model_id)->toBeNull()
        ->and($draft->bucket_name)->toBe('avatar')
        ->and($draft->draft_expires_at?->toDateTimeString())->toBe('2026-06-19 12:00:00');

    // The bytes are stored just like any add.
    Storage::disk('public')->assertExists($draft->getPath());
});

it('honours a custom draft TTL from config', function (): void {
    CarbonImmutable::setTestNow('2026-06-18 12:00:00');
    config()->set('media.drafts.ttl', 60);

    $draft = MediaLibrary::draft(__DIR__.'/../files/pixel.png')->toBucket('default');

    expect($draft->draft_expires_at?->toDateTimeString())->toBe('2026-06-18 13:00:00');
});

it('stores a draft via the model trait builder without binding the owner', function (): void {
    $user = draftUser();

    $draft = $user->addMedia(__DIR__.'/../files/pixel.png')->asDraft()->toMediaBucket('avatar');

    expect($draft->draft_token)->not->toBeNull()
        ->and($draft->model_type)->toBeNull()
        ->and($draft->model_id)->toBeNull()
        ->and($user->getMedia('avatar'))->toHaveCount(0);
});

it('does not clear an owner single-file bucket when adding a draft', function (): void {
    $user = draftUser();

    $bound = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('avatar');
    $user->addMedia(__DIR__.'/../files/pixel.png')->asDraft()->toMediaBucket('avatar');

    // The single-file bucket's bound media survives the draft add.
    expect(MediaModel::query()->whereKey($bound->id)->exists())->toBeTrue();
});

it('binds a draft to a model, setting owner and clearing the token', function (): void {
    Event::fake([DraftMediaHasBeenBound::class]);

    $user = draftUser();

    $draft = MediaLibrary::draft(__DIR__.'/../files/pixel.png')->toBucket('avatar');
    $token = (string) $draft->draft_token;

    $bound = $user->attachDraftMedia($token, 'avatar');

    expect($bound->id)->toBe($draft->id)
        ->and($bound->model_type)->toBe($user->getMorphClass())
        ->and($bound->model_id)->toBe($user->getKey())
        ->and($bound->bucket_name)->toBe('avatar')
        ->and($bound->draft_token)->toBeNull()
        ->and($bound->draft_expires_at)->toBeNull()
        ->and($user->fresh()?->getMedia('avatar'))->toHaveCount(1);

    Event::assertDispatched(DraftMediaHasBeenBound::class, fn (DraftMediaHasBeenBound $e): bool => $e->media->is($bound));
});

it('throws when binding an unknown draft token', function (): void {
    $user = draftUser();

    $user->attachDraftMedia('does-not-exist');
})->throws(DraftMediaNotFound::class);

it('throws when binding an already-bound media token', function (): void {
    $user = draftUser();

    $draft = MediaLibrary::draft(__DIR__.'/../files/pixel.png')->toBucket('avatar');
    $token = (string) $draft->draft_token;

    $user->attachDraftMedia($token, 'avatar');

    // The token is cleared on bind, so a second bind cannot find it.
    $user->attachDraftMedia($token, 'avatar');
})->throws(DraftMediaNotFound::class);

it('throws when binding an expired draft', function (): void {
    CarbonImmutable::setTestNow('2026-06-18 12:00:00');

    $user = draftUser();
    $draft = MediaLibrary::draft(__DIR__.'/../files/pixel.png')->toBucket('avatar');
    $token = (string) $draft->draft_token;

    CarbonImmutable::setTestNow('2026-06-20 12:00:00');

    $user->attachDraftMedia($token, 'avatar');
})->throws(DraftMediaExpired::class);

it('prunes only expired, never-bound drafts', function (): void {
    // Old draft: TTL 24h, expires on the 19th at 12:00.
    CarbonImmutable::setTestNow('2026-06-18 12:00:00');
    $user = draftUser();
    $expiredDraft = MediaLibrary::draft(__DIR__.'/../files/pixel.png')->toBucket('avatar');
    $expiredPath = $expiredDraft->getPath();

    // Fresh draft created later: still unexpired at prune time.
    CarbonImmutable::setTestNow('2026-06-20 11:30:00');
    $freshDraft = MediaLibrary::draft(__DIR__.'/../files/square.webp')->toBucket('avatar');

    // A bound media (token cleared) and an ordinary media must never be touched.
    $bound = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');

    // Prune at a moment past the old draft's expiry but before the fresh one's.
    CarbonImmutable::setTestNow('2026-06-20 12:00:00');

    $this->artisan('media:prune-drafts')
        ->expectsOutputToContain('Pruned 1 expired draft media.')
        ->assertSuccessful();

    expect(MediaModel::query()->whereKey($expiredDraft->id)->exists())->toBeFalse()
        ->and(MediaModel::query()->whereKey($freshDraft->id)->exists())->toBeTrue()
        ->and(MediaModel::query()->whereKey($bound->id)->exists())->toBeTrue();

    Storage::disk('public')->assertMissing($expiredPath);
});
