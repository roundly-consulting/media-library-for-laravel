<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

function verifyUser(string $name = 'Jane'): TestUser
{
    return TestUser::query()->create(['name' => $name]);
}

it('reports success when every file is present and intact', function (): void {
    $user = verifyUser();
    $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    $this->artisan('media:verify')->assertSuccessful();
});

it('fails with a non-zero exit when a file is missing', function (): void {
    $user = verifyUser();
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    Storage::disk('public')->delete($media->getPath());

    $this->artisan('media:verify')->assertFailed();
});

it('fails with a non-zero exit when bytes drifted', function (): void {
    $user = verifyUser();
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    Storage::disk('public')->put($media->getPath(), 'tampered bytes');

    $this->artisan('media:verify')->assertFailed();
});

it('limits verification to the given ids', function (): void {
    $user = verifyUser();
    $intact = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $broken = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');

    Storage::disk('public')->put($broken->getPath(), 'tampered bytes');

    // Only the intact one is verified, so the run succeeds.
    $this->artisan('media:verify', ['--ids' => (string) $intact->id])->assertSuccessful();
});

it('limits verification by model type', function (): void {
    $user = verifyUser();
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    Storage::disk('public')->put($media->getPath(), 'tampered bytes');

    // A non-matching model type verifies nothing → success.
    $this->artisan('media:verify', ['model' => 'nonexistent\\Model'])->assertSuccessful();

    // The owning model type catches the drift → failure.
    $this->artisan('media:verify', ['model' => $user->getMorphClass()])->assertFailed();
});
