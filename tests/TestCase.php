<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Tests;

use Illuminate\Support\Facades\ParallelTesting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\MediaLibrary\MediaLibraryServiceProvider;
use RoundlyConsulting\Testing\PackageTestCase;

abstract class TestCase extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('cold');
        Storage::fake('hot');
        Storage::fake('s3');

        // A real (non-faked) local disk. Faked disks always register a temporary-URL callback,
        // so they cannot exercise the "local disk cannot presign -> signed streaming route"
        // fallback. This plain local disk throws on temporaryUrl(), like production local disks.
        Storage::disk('secure')->deleteDirectory('');
    }

    /**
     * Make a faked disk behave like a presign-capable driver (S3) by registering a temporary-URL
     * callback, so the URL resolver's native `temporaryUrl()` path is exercised in tests.
     */
    protected function makeDiskPresignCapable(string $disk = 's3'): void
    {
        Storage::disk($disk)->buildTemporaryUrlsUsing(
            fn (string $path, \DateTimeInterface $expiry): string => "https://{$disk}.example.com/{$path}?expires={$expiry->getTimestamp()}"
        );
    }

    /**
     * Every provider media-library hard-requires, in registration order. A host auto-discovers
     * these; the suite must list them or the test environment is a fiction.
     *
     * @return list<class-string<ServiceProvider>>
     */
    protected function packageProviders(): array
    {
        return [MediaLibraryServiceProvider::class];
    }

    /**
     * The media migration, named by provider class (never by filename), plus the host-owned
     * fixture tables the media owners live in.
     *
     * @return list<class-string<ServiceProvider>|string>
     */
    protected function migrationSources(): array
    {
        return [
            MediaLibraryServiceProvider::class,
            __DIR__.'/database/migrations',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return [
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),

            'filesystems.disks.cold' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/disks/cold'),
            ],

            'filesystems.disks.hot' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/disks/hot'),
            ],

            // Suffixed per parallel process, as Storage::fake() suffixes its own roots: every
            // setUp() wipes this disk, and on a shared root that wipe deleted the files another
            // process had just written and was about to stream back.
            'filesystems.disks.secure' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/disks/secure'.(($token = ParallelTesting::token()) ? "_test_{$token}" : '')),
            ],
        ];
    }
}
