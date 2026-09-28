<?php

declare(strict_types=1);

use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\MediaLibrary\Contracts\FileNamer;
use RoundlyConsulting\MediaLibrary\Contracts\PathGenerator;
use RoundlyConsulting\MediaLibrary\Contracts\UrlGenerator;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;
use RoundlyConsulting\MediaLibrary\MediaLibraryServiceProvider;
use RoundlyConsulting\MediaLibrary\Support\CdnUrlGenerator;
use RoundlyConsulting\MediaLibrary\Support\DefaultFileNamer;
use RoundlyConsulting\MediaLibrary\Support\DefaultPathGenerator;
use RoundlyConsulting\MediaLibrary\Support\DefaultUrlGenerator;
use RoundlyConsulting\MediaLibrary\Support\MediaUrlResolver;

it('merges the package config', function (): void {
    expect(config('media.disk'))->toBe('public')
        ->and(config('media.default_visibility'))->toBe('public');
});

it('creates the media table', function (): void {
    expect(Schema::hasTable('media'))->toBeTrue()
        ->and(Schema::hasColumns('media', [
            'uuid', 'model_type', 'model_id', 'bucket_name', 'disk', 'variants_disk',
            'checksum', 'width', 'height', 'placeholders', 'draft_token', 'draft_expires_at',
            'order_column', 'deleted_at', 'path',
        ]))->toBeTrue();
});

it('binds the cdn url generator when cdn is enabled', function (): void {
    config()->set('media.cdn.enabled', true);

    // Re-run register so the binding picks up the new config.
    (new MediaLibraryServiceProvider($this->app))->register();

    expect(app(UrlGenerator::class))->toBeInstanceOf(CdnUrlGenerator::class);
});

it('honours a custom url_generator override over the cdn generator', function (): void {
    config()->set('media.cdn.enabled', true);
    config()->set('media.url_generator', DefaultFileNamer::class); // any other class

    (new MediaLibraryServiceProvider($this->app))->register();

    expect(app(UrlGenerator::class))->toBeInstanceOf(DefaultFileNamer::class);
});

it('binds the seam contracts from config', function (): void {
    expect(app(PathGenerator::class))->toBeInstanceOf(DefaultPathGenerator::class)
        ->and(app(FileNamer::class))->toBeInstanceOf(DefaultFileNamer::class)
        ->and(app(UrlGenerator::class))->toBeInstanceOf(DefaultUrlGenerator::class);
});

it('binds the media manager as a singleton', function (): void {
    expect(app(MediaLibraryManager::class))->toBe(app('media'))
        ->and(app(MediaLibraryManager::class))->toBeInstanceOf(MediaLibraryManager::class);
});

it('registers the streaming route when it is enabled', function (): void {
    expect(Route::has(MediaUrlResolver::ROUTE_NAME))->toBeTrue();
});

it('skips the streaming route when it is disabled', function (): void {
    config()->set('media.stream.enabled', false);

    $routes = new RouteCollection;
    app('router')->setRoutes($routes);

    $provider = new MediaLibraryServiceProvider($this->app);
    $provider->register();
    $provider->boot();

    expect($routes->getByName(MediaUrlResolver::ROUTE_NAME))->toBeNull();
});

it('keeps the published config destination and tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(MediaLibraryServiceProvider::class, 'media-config');

    expect(array_values($paths))->toBe([config_path('media.php')]);
});

it('keeps the published route destination and tag', function (): void {
    $paths = ServiceProvider::pathsToPublish(MediaLibraryServiceProvider::class, 'media-routes');

    expect(array_values($paths))->toBe([base_path('routes/media.php')]);
});
