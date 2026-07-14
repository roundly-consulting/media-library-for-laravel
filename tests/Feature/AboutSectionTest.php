<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/**
 * `php artisan about` must describe the package without ever leaking a host's infrastructure:
 * disk names, S3 buckets and CDN hosts are reported by presence and count, never by value.
 */
it('reports the package in the about command', function (): void {
    Artisan::call('about', ['--only' => 'media']);

    $rendered = Artisan::output();

    expect($rendered)->toContain('Media model')
        ->and($rendered)->toContain('Media')
        ->and($rendered)->toContain('Deduplication');
});

it('never renders a configured disk, bucket or cdn host', function (): void {
    config()->set('media.disk', 'acme-private-uploads');
    config()->set('media.variants_disk', 's3-eu-central-1-acme-variants');
    config()->set('media.queue_connection', 'tenant-redis');
    config()->set('media.queue_name', 'acme-media-variants');
    config()->set('media.queue_variants_by_default', true);
    config()->set('media.cdn.enabled', true);
    config()->set('media.cdn.base_url', 'https://cdn.acme-internal.example.com');
    config()->set('media.cdn.disks', ['acme-private-uploads']);
    config()->set('media.stream.route_prefix', 'acme-secret-media');

    Artisan::call('about', ['--only' => 'media']);

    $rendered = Artisan::output();

    // Guard the guard: an empty capture would make every assertion below a no-op.
    expect($rendered)->toContain('Media model');

    expect($rendered)
        ->not->toContain('acme-private-uploads')
        ->not->toContain('s3-eu-central-1-acme-variants')
        ->not->toContain('tenant-redis')
        ->not->toContain('acme-media-variants')
        ->not->toContain('cdn.acme-internal.example.com')
        ->not->toContain('acme-secret-media');

    // What it reports instead: switches, presence and counts.
    expect($rendered)
        ->toContain('CUSTOM')
        ->toContain('1 disk(s)');
});
