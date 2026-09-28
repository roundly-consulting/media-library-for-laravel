<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    Relation::enforceMorphMap(['user' => TestUser::class]);
});

afterEach(function (): void {
    Relation::requireMorphMap(false);
    Relation::morphMap([], false);
});

it('stores the morph alias for a mapped owner', function (): void {
    $media = MediaLibrary::for(TestUser::query()->create(['name' => 'Jane']))
        ->add(__DIR__.'/../files/pixel.png')
        ->toBucket('gallery');

    expect($media->model_type)->toBe('user');
});

it('clears a mapped model bucket when given its class name', function (): void {
    $user = TestUser::query()->create(['name' => 'Jane']);
    MediaLibrary::for($user)->add(__DIR__.'/../files/pixel.png')->toBucket('gallery');

    $this->artisan('media:clear', ['model' => TestUser::class, 'bucket' => 'gallery'])
        ->expectsOutputToContain('Cleared 1 media')
        ->assertSuccessful();
});

it('still accepts the morph alias itself', function (): void {
    $user = TestUser::query()->create(['name' => 'Jane']);
    MediaLibrary::for($user)->add(__DIR__.'/../files/pixel.png')->toBucket('gallery');

    $this->artisan('media:clear', ['model' => 'user', 'bucket' => 'gallery'])
        ->expectsOutputToContain('Cleared 1 media')
        ->assertSuccessful();
});

it('regenerates a mapped model media when given its class name', function (): void {
    $user = TestUser::query()->create(['name' => 'Jane']);
    $media = MediaLibrary::for($user)->add(__DIR__.'/../files/wide.png')->toBucket('covers');
    Storage::disk('public')->delete($media->getPath('small'));
    $media->generated_variants = [];
    $media->save();

    $this->artisan('media:regenerate', ['model' => TestUser::class])
        ->expectsOutputToContain('Regenerated variants for 1 media')
        ->assertSuccessful();
});

it('verifies a mapped model media when given its class name', function (): void {
    $user = TestUser::query()->create(['name' => 'Jane']);
    MediaLibrary::for($user)->add(__DIR__.'/../files/pixel.png')->toBucket('gallery');

    $this->artisan('media:verify', ['model' => TestUser::class])
        ->expectsOutputToContain('Verified 1 media')
        ->assertSuccessful();
});
