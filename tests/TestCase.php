<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Orchestra\Testbench\TestCase as Orchestra;
use RoundlyConsulting\MediaLibrary\MediaLibraryServiceProvider;

abstract class TestCase extends Orchestra
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

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [MediaLibraryServiceProvider::class];
    }

    /** @param  Application  $app */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(random_bytes(32)));

        $app['config']->set('filesystems.disks.cold', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/cold'),
        ]);

        $app['config']->set('filesystems.disks.hot', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/hot'),
        ]);

        $app['config']->set('filesystems.disks.secure', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/secure'),
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Schema::create('test_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('uuid_test_users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->timestamps();
        });
    }
}
