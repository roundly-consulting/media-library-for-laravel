<?php

declare(strict_types=1);
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\DefaultFileNamer;
use RoundlyConsulting\MediaLibrary\Support\DefaultPathGenerator;
use RoundlyConsulting\MediaLibrary\Support\DefaultUrlGenerator;

return [
    // Default disk for ORIGINALS when a bucket/add doesn't specify one.
    'disk' => env('MEDIA_DISK', 'public'),

    // Default disk for VARIANTS. null => same disk as the original.
    'variants_disk' => env('MEDIA_VARIANTS_DISK'),

    // Persisted Eloquent model + table. Swap model for a subclass if needed.
    'media_model' => Media::class,
    'table_name' => 'media',

    // Variants: sync by default; queue opt-in (per-variant / bucket / here).
    'queue_variants_by_default' => false,
    'queue_connection' => env('MEDIA_QUEUE_CONNECTION'),  // null => default connection
    'queue_name' => env('MEDIA_QUEUE'),             // null => default queue

    // Image driver: 'imagick' (default) | 'gd'. Auto-falls back to gd if imagick is absent.
    'image_driver' => env('MEDIA_IMAGE_DRIVER', 'imagick'),
    'variant' => [
        'quality' => 75,         // default jpg/webp quality
        'background' => '#ffffff',  // flatten color for transparent => jpg
    ],

    // When getUrl() is asked for an un-generated variant: throw (false) or
    // fall back to the original's URL (true).
    'url_fallback_to_original' => false,

    // Default lifetime (minutes) for temporary/signed URLs when not given explicitly.
    'temporary_url_default_lifetime' => 5,

    // Signed streaming route (private media on disks without native temporaryUrl()).
    // Parsed as a boolean ('false'/'0'/'off'/'no' disable it); an unparseable value keeps it on.
    'stream' => [
        'enabled' => filter_var(env('MEDIA_STREAM_ENABLED', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
        'route_prefix' => 'media',
        'middleware' => ['web'],   // 'signed' is always added by the package
    ],

    // Seams — override to customize layout/naming.
    'path_generator' => DefaultPathGenerator::class,
    'file_namer' => DefaultFileNamer::class,

    // Default visibility for new media when a bucket/add doesn't set it.
    'default_visibility' => 'public',  // 'public' | 'private'

    // Package-level max file size, in bytes: enforced on every add (and remote download) and
    // emitted by the validation rules derived from a bucket. A bucket's own ->maxFileSize()
    // overrides it; null => no package-level limit.
    'max_file_size' => 1024 * 1024 * 256,

    // addMediaFromUrl: extra request headers / timeout for the Http client.
    'remote' => [
        'headers' => [],
        'timeout' => 30,
    ],

    // Content-addressable dedup + integrity (§7.1/§7.2).
    'deduplicate' => true,         // reuse storage for identical (disk, visibility, bytes)
    'checksum_algorithm' => 'sha256',          // sha256|sha384|sha512|sha512/256|sha3-256|sha3-384|sha3-512
    'verify_checksum_on_read' => false,        // re-hash on stream; throws ChecksumMismatch on drift

    // LQIP placeholders (§6.5) — computed on add for images.
    'placeholders' => [
        'thumbhash' => true,
        'blurhash' => true,
    ],

    // Responsive srcset width ladder (§6.6) — overridable per bucket via ->responsiveWidths().
    'responsive' => [
        'widths' => [320, 640, 960, 1280, 1920],
    ],

    // Draft / temporary media (§6.7).
    'drafts' => [
        'ttl' => 1440,                         // minutes (24h) before an unbound draft is prunable
    ],

    // URL generation (§9.4) — swap for a CDN-aware generator.
    'url_generator' => DefaultUrlGenerator::class,
    'cdn' => [
        'enabled' => false,
        'base_url' => env('MEDIA_CDN_URL'),  // e.g. https://cdn.example.com
        'cache_bust' => true,                  // append ?v={updated_at} to public URLs
        'disks' => [],                    // limit CDN rewriting to these disks ([] = all public)
    ],
];
