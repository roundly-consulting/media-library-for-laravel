<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Jobs\GenerateVariantsJob;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\CdnUrlGenerator;
use RoundlyConsulting\MediaLibrary\Support\DefaultUrlGenerator;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\GdDriver;
use RoundlyConsulting\MediaLibrary\Variants\Variant;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * Sweep 2 — the non-boolean settings. A `default_visibility` typo (`privat`) was stored as-is,
 * and Media::isPublic() reads anything but `private` as PUBLIC; an `image_driver` typo became
 * imagick; `(int)`/`is_numeric` turned a junk timeout, TTL or size into 0 or the default; a blank
 * disk, prefix or table name fell back. Each now throws, naming the key.
 */
const STRICT_PIXEL = __DIR__.'/../files/pixel.png';

it('refuses a default visibility typo instead of storing it (strict config)', function (mixed $value): void {
    config()->set('media.default_visibility', $value);

    expect(fn () => MediaLibrary::add(STRICT_PIXEL)->toBucket('brand'))->toThrow(
        InvalidConfigurationException::class,
        'Configuration value [media.default_visibility] must be one of [public, private]',
    )
        ->and(Media::query()->count())->toBe(0);
})->with(['typo' => ['privat'], 'capitalised' => ['Private'], 'blank' => ['']]);

it('stores public media when the default visibility is absent (strict config)', function (): void {
    config()->set('media.default_visibility', null);

    expect(MediaLibrary::add(STRICT_PIXEL)->toBucket('brand')->visibility)->toBe('public');
});

it('refuses a blank or non-string disk setting (strict config)', function (string $key, mixed $value): void {
    config()->set($key, $value);

    expect(fn () => MediaLibrary::add(STRICT_PIXEL)->toBucket('brand'))
        ->toThrow(InvalidConfigurationException::class, "Configuration value [{$key}] must be a non-empty string");
})->with([
    'disk blank' => ['media.disk', ''],
    'disk array' => ['media.disk', ['public']],
    'variants disk blank' => ['media.variants_disk', ' '],
    'variants disk int' => ['media.variants_disk', 3],
]);

it('refuses a blank or non-string table name (strict config)', function (mixed $value): void {
    config()->set('media.table_name', $value);

    expect(fn () => (new Media)->getTable())
        ->toThrow(InvalidConfigurationException::class, 'Configuration value [media.table_name] must be a non-empty string');
})->with(['blank' => [''], 'an int' => [1]]);

it('uses the media table when the name is absent (strict config)', function (): void {
    config()->set('media.table_name', null);

    expect((new Media)->getTable())->toBe('media');
});

it('refuses a blank or non-string queue setting (strict config)', function (string $key, mixed $value): void {
    Bus::fake();
    config()->set($key, $value);

    $user = TestUser::query()->create(['name' => 'Jane']);

    expect(fn () => $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('photos'))
        ->toThrow(InvalidConfigurationException::class, "Configuration value [{$key}] must be a non-empty string");

    Bus::assertNotDispatched(GenerateVariantsJob::class);
})->with([
    'connection blank' => ['media.queue_connection', ''],
    'queue array' => ['media.queue_name', ['media']],
]);

it('refuses a junk or out-of-range variant quality (strict config)', function (mixed $value, string $message): void {
    config()->set('media.variant.quality', $value);

    expect(fn () => (new Variant('thumb'))->width(50)->resolve(new GdDriver, 'png'))
        ->toThrow(InvalidConfigurationException::class, $message);
})->with([
    'junk' => ['high', 'Configuration value [media.variant.quality] must be an integer, [high] given.'],
    'zero' => [0, 'Configuration value [media.variant.quality] must be between 1 and 100, [0] given.'],
    'too high' => [101, 'Configuration value [media.variant.quality] must be between 1 and 100, [101] given.'],
]);

it('refuses a blank variant background (strict config)', function (): void {
    config()->set('media.variant.background', '');

    expect(fn () => (new Variant('thumb'))->width(50)->resolve(new GdDriver, 'png'))
        ->toThrow(InvalidConfigurationException::class, 'media.variant.background');
});

it('reads an integer string variant quality (strict config)', function (): void {
    config()->set('media.variant.quality', '60');

    expect((new Variant('thumb'))->width(50)->resolve(new GdDriver, 'png')->quality)->toBe(60);
});

it('refuses a junk or non-positive package size cap (strict config)', function (mixed $value): void {
    config()->set('media.max_file_size', $value);

    expect(fn () => MediaLibrary::add(STRICT_PIXEL)->toBucket('brand'))
        ->toThrow(InvalidConfigurationException::class, 'media.max_file_size');
})->with(['junk' => ['256MB'], 'zero' => [0], 'negative' => [-1]]);

it('refuses a junk or non-positive remote timeout instead of waiting forever (strict config)', function (mixed $value): void {
    Http::fake(['*' => Http::response((string) file_get_contents(STRICT_PIXEL), 200, ['Content-Type' => 'image/png'])]);
    config()->set('media.remote.timeout', $value);

    expect(fn () => MediaLibrary::addFromUrl('https://example.com/a.png')->toBucket('brand'))
        ->toThrow(InvalidConfigurationException::class, 'media.remote.timeout');

    Http::assertNothingSent();
})->with(['junk' => ['thirty'], 'zero' => [0]]);

it('refuses remote headers that are not a string map (strict config)', function (mixed $value): void {
    Http::fake();
    config()->set('media.remote.headers', $value);

    expect(fn () => MediaLibrary::addFromUrl('https://example.com/a.png')->toBucket('brand'))
        ->toThrow(InvalidConfigurationException::class, 'media.remote.headers');
})->with(['a string' => ['X-Token: 1'], 'a list' => [['X-Token: 1']], 'a non-string value' => [['X-Token' => ['1']]]]);

it('refuses a blank checksum algorithm instead of reading it as sha256 (strict config)', function (): void {
    config()->set('media.checksum_algorithm', '');

    expect(fn () => MediaLibrary::add(STRICT_PIXEL)->toBucket('brand'))
        ->toThrow(InvalidConfigurationException::class, 'media.checksum_algorithm');
});

it('refuses a junk or non-positive draft TTL (strict config)', function (mixed $value): void {
    CarbonImmutable::setTestNow('2026-06-18 12:00:00');
    config()->set('media.drafts.ttl', $value);

    expect(fn () => MediaLibrary::draft(STRICT_PIXEL)->toBucket('default'))
        ->toThrow(InvalidConfigurationException::class, 'media.drafts.ttl');
})->with(['junk' => ['a day'], 'zero' => [0]]);

it('reads an integer string draft TTL (strict config)', function (): void {
    CarbonImmutable::setTestNow('2026-06-18 12:00:00');
    config()->set('media.drafts.ttl', '60');

    expect(MediaLibrary::draft(STRICT_PIXEL)->toBucket('default')->draft_expires_at?->toDateTimeString())->toBe('2026-06-18 13:00:00');
});

it('refuses a blank CDN base url or a junk disk list (strict config)', function (string $key, mixed $value): void {
    config()->set('media.cdn.base_url', 'https://cdn.example.com');
    config()->set($key, $value);

    $media = TestUser::query()->create(['name' => 'Jane'])->addMedia(STRICT_PIXEL)->toMediaBucket('gallery');

    expect(fn () => (new CdnUrlGenerator(app(DefaultUrlGenerator::class)))->getUrl($media))
        ->toThrow(InvalidConfigurationException::class, $key);
})->with([
    'base url blank' => ['media.cdn.base_url', ''],
    'base url array' => ['media.cdn.base_url', ['https://cdn.example.com']],
    'disks string' => ['media.cdn.disks', 'public'],
    'disks junk entry' => ['media.cdn.disks', ['public', 3]],
]);

it('refuses a blank stream route prefix or junk middleware list (strict config)', function (string $key, mixed $value): void {
    config()->set($key, $value);

    expect(fn () => require __DIR__.'/../../routes/media.php')->toThrow(InvalidConfigurationException::class, $key);
})->with([
    'prefix blank' => ['media.stream.route_prefix', ''],
    'middleware string' => ['media.stream.middleware', 'web'],
    'middleware junk entry' => ['media.stream.middleware', ['web', '']],
]);

it('flags a broken setting in about instead of rendering a fallback (strict config)', function (): void {
    config()->set('media.image_driver', 'GD');
    config()->set('media.max_file_size', '256MB');
    config()->set('media.drafts.ttl', 'a day');
    config()->set('media.table_name', '');

    Artisan::call('about', ['--only' => 'media']);
    $output = Artisan::output();

    expect($output)->toMatch('/Image driver\W+INVALID/')
        ->and($output)->toMatch('/Max file size\W+INVALID/')
        ->and($output)->toMatch('/Draft TTL\W+INVALID/')
        ->and($output)->toMatch('/Table\W+INVALID/');
});
