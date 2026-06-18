<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Exceptions\MediaIsNotAnImage;
use RoundlyConsulting\MediaLibrary\Jobs\GenerateVariantsJob;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;
use RoundlyConsulting\MediaLibrary\Variants\ResponsiveImageGenerator;

function responsiveUser(): TestUser
{
    return TestUser::query()->create(['name' => 'Jane']);
}

it('generates one variant per responsive width, skipping widths above the original', function (): void {
    // wide.png is 40px wide; the 'hero' ladder is [16, 24, 64] → 64 is skipped (no upscale).
    $media = responsiveUser()->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('hero');

    expect($media->hasGeneratedVariant(ResponsiveImageGenerator::variantName(16)))->toBeTrue()
        ->and($media->hasGeneratedVariant(ResponsiveImageGenerator::variantName(24)))->toBeTrue()
        ->and($media->hasGeneratedVariant(ResponsiveImageGenerator::variantName(64)))->toBeFalse();
});

it('writes responsive width files to the bucket variants disk', function (): void {
    $media = responsiveUser()->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('hero');

    Storage::disk('hot')->assertExists($media->getPath(ResponsiveImageGenerator::variantName(16)));
    Storage::disk('public')->assertMissing($media->getPath(ResponsiveImageGenerator::variantName(16)));
});

it('scales each responsive width keeping aspect ratio', function (): void {
    $media = responsiveUser()->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('banner');

    $bytes = Storage::disk('public')->get($media->getPath(ResponsiveImageGenerator::variantName(16)));
    $info = getimagesizefromstring((string) $bytes);

    // 40x20 source scaled to width 16 → 16x8.
    expect($info[0] ?? 0)->toBe(16)
        ->and($info[1] ?? 0)->toBe(8);
});

it('builds a srcset sorted ascending with only generated widths', function (): void {
    $media = responsiveUser()->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('banner');

    $srcset = $media->srcset();
    $parts = explode(', ', $srcset);

    expect($parts)->toHaveCount(2)
        ->and($parts[0])->toEndWith(' 16w')
        ->and($parts[1])->toEndWith(' 24w')
        ->and($parts[0])->toContain($media->getUrl(ResponsiveImageGenerator::variantName(16)));
});

it('returns an empty srcset when the bucket has no responsive widths', function (): void {
    $media = responsiveUser()->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');

    expect($media->srcset())->toBe('');
});

it('emits a full img tag with srcset, sizes, alt and a blur-up placeholder', function (): void {
    $media = responsiveUser()->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('banner');

    $html = $media->responsiveImage('', ['alt' => 'Hero & banner', 'sizes' => '100vw']);

    expect($html)->toStartWith('<img ')
        ->and($html)->toEndWith('>')
        ->and($html)->toContain('srcset="')
        ->and($html)->toContain('16w')
        ->and($html)->toContain('sizes="100vw"')
        ->and($html)->toContain('alt="Hero &amp; banner"')
        ->and($html)->toContain('background-image:url(')
        ->and($html)->toContain('src="');
});

it('uses the smallest generated width as the img src fallback', function (): void {
    $media = responsiveUser()->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('banner');

    $html = $media->responsiveImage();

    expect($html)->toContain('src="'.htmlspecialchars($media->getUrl(ResponsiveImageGenerator::variantName(16)), ENT_QUOTES).'"');
});

it('throws when a responsive image is requested for non-image media', function (): void {
    $media = responsiveUser()
        ->addMediaFromString('plain text')
        ->usingFileName('note.txt')
        ->toMediaBucket('gallery');

    $media->responsiveImage();
})->throws(MediaIsNotAnImage::class);

it('honours per-bucket responsiveWidths override', function (): void {
    $media = responsiveUser()->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('banner');

    expect(app(ResponsiveImageGenerator::class)->generatedWidths($media))->toBe([16, 24]);
});

it('queues responsive widths when the config default is enabled', function (): void {
    config()->set('media.queue_variants_by_default', true);
    Queue::fake();

    $media = responsiveUser()->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('banner');

    expect($media->generated_variants)->toBe([]);

    Queue::assertPushed(GenerateVariantsJob::class);
});

it('regenerates responsive widths via the regenerate command', function (): void {
    $media = responsiveUser()->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('banner');

    // Drop the generated map and the files, then regenerate.
    $media->generated_variants = [];
    $media->save();
    Storage::disk('public')->deleteDirectory($media->getPathForVariantsDirectory());

    $this->artisan('media:regenerate')->assertSuccessful();

    $media->refresh();

    expect($media->hasGeneratedVariant(ResponsiveImageGenerator::variantName(16)))->toBeTrue();
    Storage::disk('public')->assertExists($media->getPath(ResponsiveImageGenerator::variantName(16)));
});

it('falls back to the base url and includes a class attribute when no widths exist', function (): void {
    $media = responsiveUser()->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');

    $html = $media->responsiveImage('', ['class' => 'rounded']);

    expect($html)->toContain('class="rounded"')
        ->and($html)->not->toContain('srcset=')
        ->and($html)->toContain('src="'.htmlspecialchars($media->getUrl(), ENT_QUOTES).'"');
});

it('omits the blur-up style when the media has no placeholder', function (): void {
    config()->set('media.placeholders.thumbhash', false);
    config()->set('media.placeholders.blurhash', false);

    $media = responsiveUser()->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('banner');

    $html = $media->responsiveImage();

    expect($html)->not->toContain('background-image');
});

it('cleans orphaned responsive width files', function (): void {
    $media = responsiveUser()->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('banner');

    // Simulate an orphaned width file no longer referenced in generated_variants.
    $orphan = $media->getPathForVariantsDirectory().'responsive-999.png';
    Storage::disk('public')->put($orphan, 'stale');

    $this->artisan('media:clean')->assertSuccessful();

    Storage::disk('public')->assertMissing($orphan);
    Storage::disk('public')->assertExists($media->getPath(ResponsiveImageGenerator::variantName(16)));
});
