<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary;

use Illuminate\Support\ServiceProvider;
use RoundlyConsulting\MediaLibrary\Contracts\FileNamer;
use RoundlyConsulting\MediaLibrary\Contracts\ImageDriver;
use RoundlyConsulting\MediaLibrary\Contracts\PathGenerator;
use RoundlyConsulting\MediaLibrary\Contracts\UrlGenerator;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Observers\MediaObserver;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\ImageDriverFactory;

final class MediaLibraryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/media.php', 'media');

        $this->app->singleton(MediaManager::class);
        $this->app->alias(MediaManager::class, 'media');

        $this->bindFromConfig(PathGenerator::class, 'media.path_generator');
        $this->bindFromConfig(FileNamer::class, 'media.file_namer');
        $this->bindFromConfig(UrlGenerator::class, 'media.url_generator');

        // Resolved lazily: media without variants never needs an image extension, and the
        // Imagick->GD fallback (or VariantDriverUnavailable) is decided at resolution time.
        $this->app->bind(ImageDriver::class, static fn (): ImageDriver => ImageDriverFactory::make());
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if (config('media.stream.enabled') === true) {
            $this->loadRoutesFrom(__DIR__.'/../routes/media.php');
        }

        $this->registerObserver();

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/media.php' => config_path('media.php'),
            ], 'media-config');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'media-migrations');
        }
    }

    /**
     * @param  class-string  $abstract
     */
    private function bindFromConfig(string $abstract, string $configKey): void
    {
        $concrete = config($configKey);

        if (is_string($concrete)) {
            $this->app->bind($abstract, $concrete);
        }
    }

    private function registerObserver(): void
    {
        $model = config('media.media_model');

        if (! is_string($model)) {
            return;
        }

        if ($model === Media::class || is_subclass_of($model, Media::class)) {
            /** @var class-string<Media> $model */
            $model::observe(MediaObserver::class);
        }
    }
}
