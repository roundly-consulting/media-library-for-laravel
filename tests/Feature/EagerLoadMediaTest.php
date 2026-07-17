<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\UuidTestUser;

/**
 * `media.model_id` is a deliberate string morph, so an int-keyed owner's key has to reach the
 * database as a string on EVERY path. Lazy loading always did — it binds the key as a parameter.
 * Eager loading did not: Laravel's `whereIntegerInRaw` optimisation INLINES integer keys as SQL
 * literals, so Postgres saw `varchar = integer`, refused it (no implicit cast), and every
 * `->load('media')` / `with('media')` on an int-keyed host threw. SQLite's dynamic typing hid it,
 * and the suite only ever lazy-loaded — so a path every int-keyed host uses shipped untested.
 *
 * The pairs below are deliberate: each asserts lazy and eager agree. The asymmetry is the bug.
 */
it('eager-loads media for an int-keyed owner', function (): void {
    $user = TestUser::query()->create(['name' => 'Jane']);
    $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');

    $fresh = TestUser::query()->findOrFail($user->getKey());
    $fresh->load('media');

    expect($fresh->relationLoaded('media'))->toBeTrue()
        ->and($fresh->media)->toHaveCount(1)
        ->and($fresh->media->first()?->model_id)->toBe((string) $user->getKey());
});

it('eager-loads media for an int-keyed owner via with()', function (): void {
    $user = TestUser::query()->create(['name' => 'Jane']);
    $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');

    $fresh = TestUser::query()->with('media')->findOrFail($user->getKey());

    expect($fresh->relationLoaded('media'))->toBeTrue()
        ->and($fresh->media)->toHaveCount(1);
});

it('eager-loads the same media a lazy load returns for an int-keyed owner', function (): void {
    $user = TestUser::query()->create(['name' => 'Jane']);
    $expected = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');

    // Lazy: binds the key as a parameter. This has always worked.
    $lazy = TestUser::query()->findOrFail($user->getKey())->media;

    // Eager: the path that inlined the key as an integer literal.
    $eager = TestUser::query()->with('media')->findOrFail($user->getKey())->media;

    expect($lazy->pluck('id')->all())->toBe([$expected->id])
        ->and($eager->pluck('id')->all())->toBe($lazy->pluck('id')->all());
});

it('eager-loads media for many int-keyed owners without leaking between them', function (): void {
    $jane = TestUser::query()->create(['name' => 'Jane']);
    $john = TestUser::query()->create(['name' => 'John']);

    $janeMedia = $jane->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');
    $johnMedia = $john->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');

    // >1 key is what triggers the `whereIntegerInRaw` inlining path in the first place.
    $users = TestUser::query()->with('media')->orderBy('id')->get();

    expect($users)->toHaveCount(2)
        ->and($users[0]->media->pluck('id')->all())->toBe([$janeMedia->id])
        ->and($users[1]->media->pluck('id')->all())->toBe([$johnMedia->id]);
});

it('eager-loads media for a uuid-keyed owner', function (): void {
    $user = UuidTestUser::query()->create(['name' => 'Jane']);
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');

    $fresh = UuidTestUser::query()->with('media')->findOrFail($user->getKey());

    expect($fresh->relationLoaded('media'))->toBeTrue()
        ->and($fresh->media->pluck('id')->all())->toBe([$media->id]);
});

it('eager-loads the morphTo owner back from media for an int-keyed owner', function (): void {
    $user = TestUser::query()->create(['name' => 'Jane']);
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');

    $fresh = $media->newQuery()->with('model')->findOrFail($media->id);

    expect($fresh->relationLoaded('model'))->toBeTrue()
        ->and($fresh->model)->toBeInstanceOf(TestUser::class)
        ->and($fresh->model?->getKey())->toBe($user->getKey());
});
