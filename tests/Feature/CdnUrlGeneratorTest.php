<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DateTimeInterface;
use RoundlyConsulting\MediaLibrary\Contracts\UrlGenerator;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\CdnUrlGenerator;
use RoundlyConsulting\MediaLibrary\Support\DefaultUrlGenerator;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

/** A stub generator returning a fixed public URL, to exercise host/query rewriting in isolation. */
function stubCdn(string $url): CdnUrlGenerator
{
    return new CdnUrlGenerator(new class($url) implements UrlGenerator
    {
        public function __construct(private string $url) {}

        public function getUrl(Media $media, string $variant = ''): string
        {
            return $this->url;
        }

        public function getTemporaryUrl(Media $media, DateTimeInterface $expiry, string $variant = ''): string
        {
            return $this->url;
        }
    });
}

function cdnGenerator(): CdnUrlGenerator
{
    return new CdnUrlGenerator(app(DefaultUrlGenerator::class));
}

function cdnUser(string $name = 'Jane'): TestUser
{
    return TestUser::query()->create(['name' => $name]);
}

beforeEach(function (): void {
    config()->set('media.cdn.base_url', 'https://cdn.example.com');
    config()->set('media.cdn.cache_bust', false);
    config()->set('media.cdn.disks', []);
});

it('rewrites a public url onto the cdn host', function (): void {
    $user = cdnUser();
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    $url = cdnGenerator()->getUrl($media);

    expect($url)->toStartWith('https://cdn.example.com/')
        ->and($url)->toContain($media->getPath());
});

it('appends a cache-bust version param when enabled', function (): void {
    config()->set('media.cdn.cache_bust', true);

    $user = cdnUser();
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    $version = $media->updated_at?->getTimestamp();

    expect(cdnGenerator()->getUrl($media))->toContain("v={$version}");
});

it('omits the cache-bust param when disabled', function (): void {
    $user = cdnUser();
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    expect(cdnGenerator()->getUrl($media))->not->toContain('v=');
});

it('limits rewriting to the configured disks', function (): void {
    config()->set('media.cdn.disks', ['hot']);

    $user = cdnUser();
    // Stored on the `public` disk, which is not in the CDN disk list → not rewritten.
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    $generator = cdnGenerator();

    expect($generator->getUrl($media))->toBe(app(DefaultUrlGenerator::class)->getUrl($media))
        ->and($generator->getUrl($media))->not->toStartWith('https://cdn.example.com');
});

it('rewrites when the media disk is in the configured list', function (): void {
    config()->set('media.cdn.disks', ['public']);

    $user = cdnUser();
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    expect(cdnGenerator()->getUrl($media))->toStartWith('https://cdn.example.com/');
});

it('preserves a query string when rewriting the host', function (): void {
    $media = Media::factory()->create(['disk' => 'public']);

    $url = stubCdn('https://app.example.com/storage/file.png?token=abc')->getUrl($media);

    expect($url)->toBe('https://cdn.example.com/storage/file.png?token=abc');
});

it('skips the cache-bust param when the media has no updated_at', function (): void {
    config()->set('media.cdn.cache_bust', true);

    $media = Media::factory()->make(['disk' => 'public']);
    $media->updated_at = null;

    $url = stubCdn('https://app.example.com/storage/file.png')->getUrl($media);

    expect($url)->toBe('https://cdn.example.com/storage/file.png');
});

it('does not rewrite without a base url', function (): void {
    config()->set('media.cdn.base_url', null);

    $user = cdnUser();
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    expect(cdnGenerator()->getUrl($media))->toBe(app(DefaultUrlGenerator::class)->getUrl($media));
});

it('leaves temporary urls signed and never rewrites them', function (): void {
    $this->makeDiskPresignCapable('s3');
    config()->set('filesystems.disks.s3', ['driver' => 's3']);
    config()->set('media.cdn.disks', []);

    $user = cdnUser();
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')
        ->withVisibility('private')
        ->toMediaBucket('gallery', 's3');

    $expiry = CarbonImmutable::now()->addMinutes(5);

    $url = cdnGenerator()->getTemporaryUrl($media, $expiry);

    // Presigned URL goes straight to the disk host, not the CDN.
    expect($url)->toContain('s3.example.com')
        ->and($url)->not->toStartWith('https://cdn.example.com');
});

it('leaves the signed streaming route untouched for local private media', function (): void {
    $user = cdnUser();
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')
        ->withVisibility('private')
        ->toMediaBucket('gallery', 'secure');

    $expiry = CarbonImmutable::now()->addMinutes(5);

    $url = cdnGenerator()->getTemporaryUrl($media, $expiry);

    expect($url)->toContain('/media/')
        ->and($url)->toContain('signature=')
        ->and($url)->not->toStartWith('https://cdn.example.com');
});
