<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary;

use Closure;
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
use RoundlyConsulting\MediaLibrary\Support\DefaultFileNamer;
use RoundlyConsulting\MediaLibrary\Support\DefaultPathGenerator;
use RoundlyConsulting\MediaLibrary\Support\DefaultUrlGenerator;
use RoundlyConsulting\MediaLibrary\Support\MediaConfig;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\ImageDriverFactory;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Package;
use RoundlyConsulting\PackageToolkit\PackageServiceProvider;
use RoundlyConsulting\PackageToolkit\Support\Config;

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

        // Absent => the packaged default; anything that is not the contract throws on resolve.
        $this->bindFromConfig(PathGenerator::class, 'media.path_generator', DefaultPathGenerator::class);
        $this->bindFromConfig(FileNamer::class, 'media.file_namer', DefaultFileNamer::class);
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
            'Table' => self::orInvalid(MediaConfig::tableName(...)),
            'Default disk' => self::orInvalid(static fn (): string => MediaConfig::disk() === 'public' ? 'DEFAULT' : 'CUSTOM'),
            'Variants disk' => self::orInvalid(static fn (): string => MediaConfig::variantsDisk() === null ? 'SAME AS ORIGINAL' : 'CUSTOM'),
            'Image driver' => self::orInvalid(MediaConfig::imageDriver(...)),
            'Queue variants' => Config::boolean('media.queue_variants_by_default')
                ? self::orInvalid(static fn (): string => 'ON (connection '.(MediaConfig::queueConnection() === null ? 'DEFAULT' : 'SET')
                    .', queue '.(MediaConfig::queueName() === null ? 'DEFAULT' : 'SET').')')
                : 'OFF',
            'Deduplication' => Config::boolean('media.deduplicate', true)
                ? self::orInvalid(static fn (): string => 'ON ('.MediaConfig::checksumAlgorithm()->value.')')
                : 'OFF',
            'Verify checksum on read' => Config::boolean('media.verify_checksum_on_read') ? 'ON' : 'OFF',
            'Placeholders' => $this->placeholderSummary(),
            'Responsive widths' => self::orInvalid(static fn (): string => count(MediaConfig::responsiveWidths()).' width(s)'),
            'Streaming route' => Config::boolean('media.stream.enabled', true)
                ? self::orInvalid(static fn (): string => 'ON (prefix '.(MediaConfig::streamRoutePrefix() === 'media' ? 'DEFAULT' : 'SET')
                    .', '.count(MediaConfig::streamMiddleware()).' middleware)')
                : 'OFF',
            'CDN' => Config::boolean('media.cdn.enabled')
                ? self::orInvalid(static fn (): string => 'ON (base URL '.(MediaConfig::cdnBaseUrl() === null ? 'MISSING' : 'SET')
                    .', '.(($disks = MediaConfig::cdnDisks()) === [] ? 'all public disks' : count($disks).' disk(s)').')')
                : 'OFF',
            'Max file size' => self::orInvalid(static fn (): string => ($max = MediaConfig::maxFileSize()) === null ? 'NO LIMIT' : $max.' B'),
            'Draft TTL' => self::orInvalid(static fn (): string => MediaConfig::draftTtl().' min'),
        ];
    }

    /**
     * A strict read rendered for `about`, or `INVALID` when the setting is broken — so
     * `php artisan about` still works on a misconfigured host while every real read throws.
     *
     * @param  Closure(): string  $read
     */
    private static function orInvalid(Closure $read): string
    {
        try {
            return $read();
        } catch (InvalidConfigurationException) {
            return 'INVALID';
        }
    }

    private function placeholderSummary(): string
    {
        $enabled = [];

        if (Config::boolean('media.placeholders.thumbhash', true)) {
            $enabled[] = 'thumbhash';
        }

        if (Config::boolean('media.placeholders.blurhash', true)) {
            $enabled[] = 'blurhash';
        }

        return $enabled === [] ? 'OFF' : implode(', ', $enabled);
    }

    /**
     * Bind the URL generator. A host-set `media.url_generator` always wins (override) and must
     * implement UrlGenerator; otherwise — not set (absent, null or blank) or the default — the
     * CDN-aware generator is used when `media.cdn.enabled`, else the default.
     */
    private function bindUrlGenerator(): void
    {
        $configured = config('media.url_generator');
        $notSet = $configured === null || (is_string($configured) && trim($configured) === '');

        if (! $notSet && $configured !== DefaultUrlGenerator::class) {
            // A host override: anything that is not a UrlGenerator throws on resolve.
            $this->bindFromConfig(UrlGenerator::class, 'media.url_generator', DefaultUrlGenerator::class);

            return;
        }

        if (Config::boolean('media.cdn.enabled')) {
            $this->app->bind(
                UrlGenerator::class,
                static fn ($app): CdnUrlGenerator => new CdnUrlGenerator($app->make(DefaultUrlGenerator::class)),
            );

            return;
        }

        $this->app->bind(UrlGenerator::class, DefaultUrlGenerator::class);
    }
}
