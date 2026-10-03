<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Buckets\MediaBucket;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;
use RoundlyConsulting\MediaLibrary\Variants\ResponsiveImageGenerator;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

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

it('refuses a malformed configured ladder instead of using the built-in one (strict config)', function (mixed $widths): void {
    config()->set('media.responsive.widths', $widths);

    expect(fn () => (new MediaBucket('x'))->responsiveWidths())
        ->toThrow(InvalidConfigurationException::class, 'media.responsive.widths');
})->with([
    'a string' => ['nonsense'],
    'a zero width' => [[320, 0]],
    'a junk width' => [[320, 'wide']],
]);

it('uses the built-in ladder when the configured one is absent (strict config)', function (): void {
    config()->set('media.responsive.widths', null);

    expect((new MediaBucket('x'))->responsiveWidths()->getResponsiveWidths())->toBe([320, 640, 960, 1280, 1920]);
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
