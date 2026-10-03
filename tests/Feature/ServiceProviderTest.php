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
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\HostUrlGenerator;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

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
    config()->set('media.url_generator', HostUrlGenerator::class);

    (new MediaLibraryServiceProvider($this->app))->register();

    expect(app(UrlGenerator::class))->toBeInstanceOf(HostUrlGenerator::class);
});

it('refuses a seam class that does not implement its contract (strict config)', function (string $key, string $contract): void {
    foreach ([$contract === FileNamer::class ? DefaultPathGenerator::class : DefaultFileNamer::class, 'App\\Missing\\Seam', false] as $value) {
        config()->set($key, $value);

        (new MediaLibraryServiceProvider($this->app))->register();

        expect(fn () => app($contract))->toThrow(
            InvalidConfigurationException::class,
            "Configuration value [{$key}] must be a class-string of [{$contract}]",
        );
    }
})->with([
    'path generator' => ['media.path_generator', PathGenerator::class],
    'file namer' => ['media.file_namer', FileNamer::class],
    'url generator' => ['media.url_generator', UrlGenerator::class],
]);

it('binds the default seam when its class is blank, which is not set (strict config)', function (string $blank): void {
    config()->set('media.path_generator', $blank);
    config()->set('media.file_namer', $blank);
    config()->set('media.url_generator', $blank);

    (new MediaLibraryServiceProvider($this->app))->register();

    expect(app(PathGenerator::class))->toBeInstanceOf(DefaultPathGenerator::class)
        ->and(app(FileNamer::class))->toBeInstanceOf(DefaultFileNamer::class)
        ->and(app(UrlGenerator::class))->toBeInstanceOf(DefaultUrlGenerator::class);
})->with(['empty' => [''], 'whitespace' => [' ']]);

it('keeps the cdn url generator when the url_generator override is blank (strict config)', function (): void {
    config()->set('media.cdn.enabled', true);
    config()->set('media.url_generator', '');

    (new MediaLibraryServiceProvider($this->app))->register();

    expect(app(UrlGenerator::class))->toBeInstanceOf(CdnUrlGenerator::class);
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
