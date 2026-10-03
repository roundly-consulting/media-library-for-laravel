<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Contracts\UrlGenerator;
use RoundlyConsulting\MediaLibrary\Exceptions\ChecksumMismatch;
use RoundlyConsulting\MediaLibrary\Exceptions\TemporaryUrlNotSupported;
use RoundlyConsulting\MediaLibrary\Jobs\GenerateVariantsJob;
use RoundlyConsulting\MediaLibrary\MediaLibraryServiceProvider;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\CdnUrlGenerator;
use RoundlyConsulting\MediaLibrary\Support\DefaultUrlGenerator;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * Every switch is read as a boolean, not compared with `=== true` / `!== false`. A host that
 * feeds one from its own published config through env() gets the STRING '1' / 'off' — env()
 * only converts 'true'/'false' — and a strict comparison read '1' as off and 'off' as on.
 */
function booleanUser(): TestUser
{
    return TestUser::query()->create(['name' => 'Jane']);
}

function mediaAbout(): string
{
    Artisan::call('about', ['--only' => 'media']);

    return Artisan::output();
}

dataset('truthy strings', ['1', 'on', 'yes']);
dataset('falsy strings', ['0', 'off', 'no']);

it('queues variants when the default is a truthy string', function (string $value): void {
    config()->set('media.queue_variants_by_default', $value);
    Bus::fake();

    $media = booleanUser()->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    expect($media->hasGeneratedVariant('small'))->toBeFalse()
        ->and(mediaAbout())->toMatch('/Queue variants\s*\.*\s*ON/');

    Bus::assertDispatched(GenerateVariantsJob::class);
})->with('truthy strings');

it('deduplicates when the switch is a truthy string', function (string $value): void {
    config()->set('media.deduplicate', $value);
    $user = booleanUser();

    $a = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $b = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    expect($b->getPath())->toBe($a->getPath())
        ->and(Storage::disk('public')->allFiles())->toHaveCount(1)
        ->and(mediaAbout())->toMatch('/Deduplication\s*\.*\s*ON/');
})->with('truthy strings');

it('stores fresh copies when dedup is a falsy string', function (string $value): void {
    config()->set('media.deduplicate', $value);
    $user = booleanUser();

    $a = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $b = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    expect($b->getPath())->not->toBe($a->getPath())
        ->and(mediaAbout())->toMatch('/Deduplication\s*\.*\s*OFF/');
})->with('falsy strings');

it('verifies checksums on read when the switch is a truthy string', function (string $value): void {
    config()->set('media.verify_checksum_on_read', $value);

    $media = booleanUser()->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    Storage::disk('public')->put($media->getPath(), 'tampered bytes');

    expect(fn (): mixed => $media->getStream())->toThrow(ChecksumMismatch::class)
        ->and(mediaAbout())->toMatch('/Verify checksum on read\s*\.*\s*ON/');
})->with('truthy strings');

it('binds the cdn generator when cdn is a truthy string', function (string $value): void {
    config()->set('media.cdn.enabled', $value);
    (new MediaLibraryServiceProvider($this->app))->register();

    expect(app(UrlGenerator::class))->toBeInstanceOf(CdnUrlGenerator::class)
        ->and(mediaAbout())->toMatch('/CDN\s*\.*\s*ON/');
})->with('truthy strings');

it('keeps the default generator when cdn is a falsy string', function (string $value): void {
    config()->set('media.cdn.enabled', $value);
    (new MediaLibraryServiceProvider($this->app))->register();

    expect(app(UrlGenerator::class))->toBeInstanceOf(DefaultUrlGenerator::class)
        ->and(mediaAbout())->toMatch('/CDN\s*\.*\s*OFF/');
})->with('falsy strings');

it('busts the cdn cache when the switch is a truthy string', function (string $value): void {
    config()->set('media.cdn.base_url', 'https://cdn.example.com');
    config()->set('media.cdn.cache_bust', $value);

    $media = booleanUser()->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $version = $media->updated_at?->getTimestamp();

    expect((new CdnUrlGenerator(app(DefaultUrlGenerator::class)))->getUrl($media))->toContain("v={$version}");
})->with('truthy strings');

it('falls back to the original url when the switch is a truthy string', function (string $value): void {
    config()->set('media.url_fallback_to_original', $value);

    $media = Media::factory()->create([
        'uuid' => '00000000-0000-4000-8000-0000000000b1', 'file_name' => 'a.jpg', 'disk' => 'public', 'visibility' => 'public',
    ]);

    expect($media->getUrl('thumb'))->toContain('/a.jpg');
})->with('truthy strings');

it('skips placeholders whose toggle is a falsy string', function (string $value): void {
    config()->set('media.placeholders.thumbhash', $value);
    config()->set('media.placeholders.blurhash', $value);

    $media = booleanUser()->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('gallery');

    expect($media->placeholder())->toBe([])
        ->and(mediaAbout())->toMatch('/Placeholders\s*\.*\s*OFF/');
})->with('falsy strings');

it('refuses streamed temporary urls when the route switch is a falsy string', function (string $value): void {
    config()->set('media.stream.enabled', $value);

    $media = Media::factory()->create([
        'uuid' => '00000000-0000-4000-8000-0000000000b2', 'file_name' => 'a.jpg', 'disk' => 'secure', 'visibility' => 'private',
    ]);

    expect(mediaAbout())->toMatch('/Streaming route\s*\.*\s*OFF/')
        ->and(fn (): string => $media->getTemporaryUrl(CarbonImmutable::now()->addMinutes(5)))
        ->toThrow(TemporaryUrlNotSupported::class);
})->with('falsy strings');

it('issues streamed temporary urls when the route switch is a truthy string', function (string $value): void {
    config()->set('media.stream.enabled', $value);

    $media = Media::factory()->create([
        'uuid' => '00000000-0000-4000-8000-0000000000b3', 'file_name' => 'a.jpg', 'disk' => 'secure', 'visibility' => 'private',
    ]);

    expect($media->getTemporaryUrl(CarbonImmutable::now()->addMinutes(5)))->toContain('/media/')
        ->and(mediaAbout())->toMatch('/Streaming route\s*\.*\s*ON/');
})->with('truthy strings');

it('refuses to resolve a streamed url when the route switch is unreadable (strict config)', function (): void {
    config()->set('media.stream.enabled', 'disabled');

    $media = Media::factory()->create([
        'uuid' => '00000000-0000-4000-8000-0000000000b9', 'file_name' => 'a.jpg', 'disk' => 'secure', 'visibility' => 'private',
    ]);

    expect(fn (): string => $media->getTemporaryUrl(CarbonImmutable::now()->addMinutes(5)))
        ->toThrow(InvalidConfigurationException::class, 'media.stream.enabled');
});
