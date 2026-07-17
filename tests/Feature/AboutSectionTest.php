<?php

declare(strict_types=1);

/**
 * The secret-safe `about` capture (A).
 *
 * Purchases #13 is the bug this exists for: the fleet's most credential-heavy `about` section
 * was guarded by negative assertions against `app(Kernel::class)->output()`, which returns `''`.
 * Every "does not leak" check was vacuous — passing against empty output.
 *
 * The hand-rolled version this replaces was already the *correct* shape (Artisan::call +
 * Artisan::output(), with one positive assertion guarding the guard), which is why it caught
 * nothing new. The expectation is still worth adopting: it makes the ordering structural rather
 * than a convention this file happened to follow — output non-empty, then every `mustRender`
 * present, and only THEN no secret rendered — and `mustRender` is required and non-empty, so a
 * future edit cannot quietly turn this back into a negative-only check.
 *
 * What media has to hide is a host's infrastructure topology: disk names, S3 buckets, CDN hosts,
 * queue connections and the streaming route prefix are all reported by presence and count, never
 * by value.
 */
it('renders the media section without leaking the host topology it describes', function (): void {
    config()->set('media.disk', 'acme-private-uploads');
    config()->set('media.variants_disk', 's3-eu-central-1-acme-variants');
    config()->set('media.queue_connection', 'tenant-redis');
    config()->set('media.queue_name', 'acme-media-variants');
    config()->set('media.queue_variants_by_default', true);
    config()->set('media.cdn.enabled', true);
    config()->set('media.cdn.base_url', 'https://cdn.acme-internal.example.com');
    config()->set('media.cdn.disks', ['acme-private-uploads']);
    config()->set('media.stream.route_prefix', 'acme-secret-media');

    expect('media')->toLeakNoSecrets(
        secrets: [
            'acme-private-uploads',
            's3-eu-central-1-acme-variants',
            'tenant-redis',
            'acme-media-variants',
            'cdn.acme-internal.example.com',
            'acme-secret-media',
        ],
        mustRender: [
            'Media model',
            'Deduplication',
            'Streaming route',
            'CDN',
            // The positive proof that the topology lines REPORT rather than sit silently
            // empty — which is what makes hiding the values meaningful rather than accidental.
            'CUSTOM',
            '1 disk(s)',
        ],
    );
});

/**
 * The other half of #27: `media.max_file_size` was shipped as the package-level upload cap and
 * nothing read it — an upload endpoint with no size limit. It is fixed, and the reverse config
 * contract now pins that it stays read. This pins the operator-facing half: a host reading
 * `about` must be told the truth about whether a cap is in force, because "NO LIMIT" rendered
 * while a limit applies (or the reverse) is how #27 stayed invisible to the people running it.
 */
it('reports the max file size cap honestly', function (): void {
    config()->set('media.max_file_size', 1024);

    expect('media')->toLeakNoSecrets(
        secrets: ['NO LIMIT'],
        mustRender: ['Max file size', '1024 B'],
    );
});

it('reports no limit when the cap is disabled', function (): void {
    config()->set('media.max_file_size', null);

    expect('media')->toLeakNoSecrets(
        secrets: [],
        mustRender: ['Max file size', 'NO LIMIT'],
    );
});
