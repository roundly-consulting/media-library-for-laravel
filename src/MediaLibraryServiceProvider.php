<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary;

use RoundlyConsulting\MediaLibrary\Commands\CleanCommand;
use RoundlyConsulting\MediaLibrary\Commands\ClearCommand;
use RoundlyConsulting\MediaLibrary\Commands\PruneDraftsCommand;
use RoundlyConsulting\MediaLibrary\Commands\RegenerateVariantsCommand;
use RoundlyConsulting\MediaLibrary\Commands\VerifyCommand;
use RoundlyConsulting\MediaLibrary\Contracts\FileNamer;
use RoundlyConsulting\MediaLibrary\Contracts\ImageDriver;
use RoundlyConsulting\MediaLibrary\Contracts\PathGenerator;
use RoundlyConsulting\MediaLibrary\Contracts\UrlGenerator;
use RoundlyConsulting\MediaLibrary\Observers\MediaObserver;
use RoundlyConsulting\MediaLibrary\Support\CdnUrlGenerator;
use RoundlyConsulting\MediaLibrary\Support\DefaultUrlGenerator;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\ImageDriverFactory;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;

final class MediaLibraryServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('media')
            ->hasConfigFile()
            ->hasMigrations()
            ->hasRoutes('media.php', enabledVia: 'media.stream.enabled')
            ->hasCommands([
                RegenerateVariantsCommand::class,
                CleanCommand::class,
                ClearCommand::class,
                PruneDraftsCommand::class,
                VerifyCommand::class,
            ])
            ->contributesToAbout(fn (): array => $this->aboutData());
    }

    public function register(): void
    {
        parent::register();

        $this->app->singleton(MediaLibraryManager::class);
        $this->app->alias(MediaLibraryManager::class, 'media');

        $this->bindSeamFromConfig(PathGenerator::class, 'media.path_generator');
        $this->bindSeamFromConfig(FileNamer::class, 'media.file_namer');
        $this->bindUrlGenerator();

        // Resolved lazily: media without variants never needs an image extension, and the
        // Imagick->GD fallback (or VariantDriverUnavailable) is decided at resolution time.
        $this->app->bind(ImageDriver::class, static fn (): ImageDriver => ImageDriverFactory::make());
    }

    public function boot(): void
    {
        parent::boot();

        MediaModel::class()::observe(MediaObserver::class);
    }

    /**
     * The `php artisan about` payload. Secret-safe: disks, CDN hosts and route prefixes are a
     * host's infrastructure topology, so they are reported by presence and count, never by value.
     *
     * @return array<string, string>
     */
    private function aboutData(): array
    {
        return [
            'Media model' => class_basename(MediaModel::class()),
            'Table' => is_string($table = config('media.table_name')) ? $table : 'media',
            'Default disk' => config('media.disk') === 'public' ? 'DEFAULT' : 'CUSTOM',
            'Variants disk' => is_string(config('media.variants_disk')) ? 'CUSTOM' : 'SAME AS ORIGINAL',
            'Image driver' => is_string($driver = config('media.image_driver')) ? $driver : 'imagick',
            'Queue variants' => config('media.queue_variants_by_default') === true
                ? 'ON (connection '.(is_string(config('media.queue_connection')) ? 'SET' : 'DEFAULT').', queue '.(is_string(config('media.queue_name')) ? 'SET' : 'DEFAULT').')'
                : 'OFF',
            'Deduplication' => config('media.deduplicate') === true ? 'ON ('.$this->checksumAlgorithm().')' : 'OFF',
            'Verify checksum on read' => config('media.verify_checksum_on_read') === true ? 'ON' : 'OFF',
            'Placeholders' => $this->placeholderSummary(),
            'Responsive widths' => count($this->configArray('media.responsive.widths')).' width(s)',
            'Streaming route' => config('media.stream.enabled') === true
                ? 'ON (prefix '.(is_string(config('media.stream.route_prefix')) ? 'SET' : 'DEFAULT').', '.count($this->configArray('media.stream.middleware')).' middleware)'
                : 'OFF',
            'CDN' => config('media.cdn.enabled') === true
                ? 'ON (base URL '.(is_string(config('media.cdn.base_url')) && config('media.cdn.base_url') !== '' ? 'SET' : 'MISSING').', '.$this->cdnDiskSummary().')'
                : 'OFF',
            'Max file size' => is_numeric($max = config('media.max_file_size')) ? (int) $max.' B' : 'NO LIMIT',
            'Draft TTL' => (is_numeric($ttl = config('media.drafts.ttl')) ? (int) $ttl : 1440).' min',
        ];
    }

    private function checksumAlgorithm(): string
    {
        $algorithm = config('media.checksum_algorithm');

        return is_string($algorithm) && $algorithm !== '' ? $algorithm : 'sha256';
    }

    private function placeholderSummary(): string
    {
        $enabled = [];

        if (config('media.placeholders.thumbhash') !== false) {
            $enabled[] = 'thumbhash';
        }

        if (config('media.placeholders.blurhash') !== false) {
            $enabled[] = 'blurhash';
        }

        return $enabled === [] ? 'OFF' : implode(', ', $enabled);
    }

    private function cdnDiskSummary(): string
    {
        $disks = $this->configArray('media.cdn.disks');

        return $disks === [] ? 'all public disks' : count($disks).' disk(s)';
    }

    /** @return array<array-key, mixed> */
    private function configArray(string $key): array
    {
        $value = config($key);

        return is_array($value) ? $value : [];
    }

    /**
     * Bind one of the package's pluggable seams to the class named at `$configKey`. Unlike the
     * toolkit's `bindFromConfig()`, an unset/non-string value binds nothing — the seam's default
     * is expressed in the shipped config, not here.
     *
     * @param  class-string  $abstract
     */
    private function bindSeamFromConfig(string $abstract, string $configKey): void
    {
        $concrete = config($configKey);

        if (is_string($concrete)) {
            $this->app->bind($abstract, $concrete);
        }
    }

    /**
     * Bind the URL generator. A host-set `media.url_generator` always wins (override); otherwise
     * the CDN-aware generator is used when `media.cdn.enabled`, falling back to the default.
     */
    private function bindUrlGenerator(): void
    {
        $configured = config('media.url_generator');

        if (is_string($configured) && $configured !== DefaultUrlGenerator::class) {
            $this->app->bind(UrlGenerator::class, $configured);

            return;
        }

        if (config('media.cdn.enabled') === true) {
            $this->app->bind(
                UrlGenerator::class,
                static fn ($app): CdnUrlGenerator => new CdnUrlGenerator($app->make(DefaultUrlGenerator::class)),
            );

            return;
        }

        $this->app->bind(UrlGenerator::class, DefaultUrlGenerator::class);
    }
}
