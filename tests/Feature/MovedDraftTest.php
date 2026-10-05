<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\MediaLibrary\Exceptions\DraftMediaNotFound;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\MediaConfig;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

/*
 * Moving a draft to an owner (or to a global bucket) settles it, like binding does. A draft that
 * kept its token stayed hidden from bucket(), could be re-bound by anyone holding the token, and
 * was pruned — rows and files — once its TTL lapsed, owner or not.
 */

afterEach(fn () => CarbonImmutable::setTestNow());

it('settles a draft moved to an owner: the token no longer binds it', function (): void {
    $draft = MediaLibrary::draft(__DIR__.'/../files/pixel.png')->toBucket();
    $token = (string) $draft->draft_token;
    $ada = TestUser::query()->create(['name' => 'Ada']);
    $bob = TestUser::query()->create(['name' => 'Bob']);

    $draft->move($ada, 'gallery');

    $fresh = Media::query()->findOrFail($draft->id);

    expect($fresh->draft_token)->toBeNull()
        ->and($fresh->draft_expires_at)->toBeNull()
        ->and(fn () => MediaLibrary::for($bob)->bindDraft($token, 'gallery'))->toThrow(DraftMediaNotFound::class);

    expect((string) Media::query()->findOrFail($draft->id)->model_id)->toBe((string) $ada->id);
});

it('settles a draft moved to a global bucket: bucket() lists it', function (): void {
    $draft = MediaLibrary::draft(__DIR__.'/../files/pixel.png')->toBucket();

    $draft->move(null, 'library');

    expect(MediaLibrary::bucket('library')->pluck('id')->all())->toBe([$draft->id]);
});

it('never prunes a draft that was moved to an owner', function (): void {
    $draft = MediaLibrary::draft(__DIR__.'/../files/pixel.png')->toBucket();
    $ada = TestUser::query()->create(['name' => 'Ada']);

    $draft->move($ada, 'gallery');

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(MediaConfig::draftTtl())->addDays(2));

    expect(MediaLibrary::pruneDrafts())->toBe(0)
        ->and(Media::query()->find($draft->id))->not->toBeNull();
});

it('prunes only unowned drafts, even when an owned row still carries a token', function (): void {
    $draft = MediaLibrary::draft(__DIR__.'/../files/pixel.png')->toBucket();
    $owned = MediaLibrary::draft(__DIR__.'/../files/wide.png')->toBucket();
    $ada = TestUser::query()->create(['name' => 'Ada']);

    // A row an earlier version left behind: owned, yet still carrying its draft token.
    Media::query()->whereKey($owned->id)->update(['model_type' => $ada->getMorphClass(), 'model_id' => (string) $ada->id]);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(MediaConfig::draftTtl())->addDays(2));

    expect(MediaLibrary::pruneDrafts())->toBe(1)
        ->and(Media::query()->find($draft->id))->toBeNull()
        ->and(Media::query()->find($owned->id))->not->toBeNull();
});
