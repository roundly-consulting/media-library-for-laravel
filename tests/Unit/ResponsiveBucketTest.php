<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Buckets\MediaBucket;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;
use RoundlyConsulting\MediaLibrary\Variants\ResponsiveImageGenerator;

it('is opt-in: a bucket has no responsive widths by default', function (): void {
    $bucket = new MediaBucket('x');

    expect($bucket->hasResponsiveWidths())->toBeFalse()
        ->and($bucket->getResponsiveWidths())->toBe([]);
});

it('uses the config default ladder when responsiveWidths is called with no args', function (): void {
    config()->set('media.responsive.widths', [100, 200, 300]);

    $bucket = (new MediaBucket('x'))->responsiveWidths();

    expect($bucket->hasResponsiveWidths())->toBeTrue()
        ->and($bucket->getResponsiveWidths())->toBe([100, 200, 300]);
});

it('falls back to the built-in ladder when config is malformed', function (): void {
    config()->set('media.responsive.widths', 'nonsense');

    $bucket = (new MediaBucket('x'))->responsiveWidths();

    expect($bucket->getResponsiveWidths())->toBe([320, 640, 960, 1280, 1920]);
});

it('dedupes, drops non-positive widths and sorts ascending', function (): void {
    $bucket = (new MediaBucket('x'))->responsiveWidths([640, 320, 640, 0, -10, 960]);

    expect($bucket->getResponsiveWidths())->toBe([320, 640, 960]);
});

it('applies a responsive output format to generated widths', function (): void {
    $user = TestUser::query()->create(['name' => 'Jane']);

    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('webphero');

    $path = $media->getPath(ResponsiveImageGenerator::variantName(16));

    expect($path)->toEndWith('.webp');
    Storage::disk('public')->assertExists($path);
});
