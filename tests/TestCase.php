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
    }

    /** @return array<int, class-string> */
    protected function getPackageProviders($app): array
    {
        return [MediaLibraryServiceProvider::class];
    }

    /** @param  Application  $app */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('filesystems.disks.cold', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/cold'),
        ]);

        $app['config']->set('filesystems.disks.hot', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/hot'),
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
    }
}
