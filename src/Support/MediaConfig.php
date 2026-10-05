<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

use RoundlyConsulting\MediaLibrary\Enums\ChecksumAlgorithm;
use RoundlyConsulting\MediaLibrary\Exceptions\InvalidVisibility;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Strict readers for the package's non-boolean settings.
 *
 * A setting that is not set — absent, null, or blank like a host's `KEY=` — takes its default
 * (or, for an optional setting such as the variants disk or CDN URL, none). Anything else
 * unusable — a `privat` visibility (which Media::isPublic() would have read as PUBLIC), a `GD`
 * driver, a `thirty` timeout that `(int)` made 0 (no timeout), a non-string disk or table name —
 * throws {@see InvalidConfigurationException} naming the key, instead of quietly falling back.
 *
 * @internal
 */
final class MediaConfig
{
    public const string VISIBILITY_PUBLIC = 'public';

    public const string VISIBILITY_PRIVATE = 'private';

    /** The built-in responsive ladder, used when `media.responsive.widths` is unset. */
    public const array DEFAULT_WIDTHS = [320, 640, 960, 1280, 1920];

    public static function disk(): string
    {
        return self::string('media.disk', 'public');
    }

    /** The configured variants disk, or null for "same disk as the original". */
    public static function variantsDisk(): ?string
    {
        return self::optionalString('media.variants_disk');
    }

    public static function tableName(): string
    {
        return self::string('media.table_name', 'media');
    }

    /** The queue connection for variant jobs, or null for the default connection. */
    public static function queueConnection(): ?string
    {
        return self::optionalString('media.queue_connection');
    }

    /** The queue for variant jobs, or null for the default queue. */
    public static function queueName(): ?string
    {
        return self::optionalString('media.queue_name');
    }

    /** `imagick` or `gd`. */
    public static function imageDriver(): string
    {
        return Config::oneOf('media.image_driver', ['imagick', 'gd'], 'imagick');
    }

    /** The largest image (width x height) the package decodes (at least 1), or null for no cap. */
    public static function maxImagePixels(): ?int
    {
        return self::unlessBlank(config('media.max_image_pixels')) === null
            ? null
            : Config::integer('media.max_image_pixels', 50_000_000, 1);
    }

    /** Default jpg/webp quality, 1–100. */
    public static function variantQuality(): int
    {
        return Config::integer('media.variant.quality', 75, 1, 100);
    }

    public static function variantBackground(): string
    {
        return self::string('media.variant.background', '#ffffff');
    }

    /** Default lifetime, in minutes, of temporary / signed URLs (at least 1). */
    public static function temporaryUrlLifetime(): int
    {
        return Config::integer('media.temporary_url_default_lifetime', 5, 1);
    }

    public static function streamRoutePrefix(): string
    {
        return self::string('media.stream.route_prefix', 'media');
    }

    /**
     * The streaming route's middleware (`signed` is always added on top).
     *
     * @return list<string>
     */
    public static function streamMiddleware(): array
    {
        return self::stringList('media.stream.middleware', self::unlessBlank(config('media.stream.middleware')) ?? ['web']);
    }

    /** `public` or `private`. */
    public static function defaultVisibility(): string
    {
        return Config::oneOf(
            'media.default_visibility',
            [self::VISIBILITY_PUBLIC, self::VISIBILITY_PRIVATE],
            self::VISIBILITY_PUBLIC,
        );
    }

    /**
     * A visibility a caller asked for, checked: exactly `public` or `private`.
     *
     * @throws InvalidVisibility for anything else (`Private` included — read as public, it would
     *                           have published the file)
     */
    public static function visibility(string $visibility): string
    {
        if ($visibility !== self::VISIBILITY_PUBLIC && $visibility !== self::VISIBILITY_PRIVATE) {
            throw InvalidVisibility::given($visibility);
        }

        return $visibility;
    }

    /** The package-level size cap in bytes (at least 1), or null when there is none. */
    public static function maxFileSize(): ?int
    {
        return self::unlessBlank(config('media.max_file_size')) === null
            ? null
            : Config::integer('media.max_file_size', 1, 1);
    }

    /**
     * Extra request headers for `addMediaFromUrl()`.
     *
     * @return array<string, string>
     */
    public static function remoteHeaders(): array
    {
        $key = 'media.remote.headers';
        $headers = self::unlessBlank(config($key)) ?? [];

        if (! is_array($headers)) {
            throw self::invalid($key, 'a map of header name => value', $headers);
        }

        $clean = [];

        foreach ($headers as $name => $value) {
            if (! is_string($name) || ! is_string($value)) {
                throw self::invalid($key, 'a map of header name => value', is_string($name) ? $value : $name);
            }

            $clean[$name] = $value;
        }

        return $clean;
    }

    /** The `addMediaFromUrl()` HTTP timeout in seconds (at least 1 — 0 would mean none). */
    public static function remoteTimeout(): int
    {
        return Config::integer('media.remote.timeout', 30, 1);
    }

    /** Whether `addMediaFromUrl()` refuses private and reserved network addresses (default on). */
    public static function blockPrivateNetworks(): bool
    {
        return Config::boolean('media.remote.block_private_networks', true);
    }

    /**
     * Host names, addresses and `address/prefix` ranges exempt from the private-network guard.
     *
     * @return list<string>
     */
    public static function allowedPrivateHosts(): array
    {
        $key = 'media.remote.allowed_private_hosts';
        $entries = self::stringList($key, self::unlessBlank(config($key)) ?? []);

        foreach ($entries as $entry) {
            if (str_contains($entry, '/') && ! PrivateNetworks::isValidRange($entry)) {
                throw self::invalid($key, 'a list of host names, addresses and address/prefix ranges', $entry);
            }
        }

        return $entries;
    }

    public static function checksumAlgorithm(): ChecksumAlgorithm
    {
        return Config::enum('media.checksum_algorithm', ChecksumAlgorithm::class, ChecksumAlgorithm::Sha256);
    }

    /**
     * The responsive width ladder; the built-in one when unset.
     *
     * @return list<int>
     */
    public static function responsiveWidths(): array
    {
        $key = 'media.responsive.widths';
        $widths = self::unlessBlank(config($key));

        if ($widths === null) {
            return self::DEFAULT_WIDTHS;
        }

        if (! is_array($widths) || ! array_is_list($widths)) {
            throw self::invalid($key, 'a list of positive integers', $widths);
        }

        $clean = [];

        foreach ($widths as $width) {
            // Validated under the setting's own key, so the message names it.
            $clean[] = Config::for([$key => $width])->integer($key, 1, 1);
        }

        return array_values(array_unique($clean));
    }

    /** Minutes before an unbound draft is prunable (at least 1). */
    public static function draftTtl(): int
    {
        return Config::integer('media.drafts.ttl', 1440, 1);
    }

    /** The CDN base URL, or null when none is configured (URLs are then not rewritten). */
    public static function cdnBaseUrl(): ?string
    {
        return self::optionalString('media.cdn.base_url');
    }

    /**
     * The disks the CDN rewrites; empty for every public disk.
     *
     * @return list<string>
     */
    public static function cdnDisks(): array
    {
        return self::stringList('media.cdn.disks', self::unlessBlank(config('media.cdn.disks')) ?? []);
    }

    private static function string(string $key, string $default): string
    {
        return self::optionalString($key) ?? $default;
    }

    private static function optionalString(string $key): ?string
    {
        $value = self::unlessBlank(config($key));

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw InvalidConfigurationException::notAString($key, $value);
        }

        return $value;
    }

    /**
     * A raw config value, with a blank string (`''` or whitespace — a host's `KEY=`) read as
     * null: not set, exactly like an absent key.
     */
    private static function unlessBlank(mixed $value): mixed
    {
        return is_string($value) && trim($value) === '' ? null : $value;
    }

    /**
     * @return list<string>
     */
    private static function stringList(string $key, mixed $values): array
    {
        if (! is_array($values) || ! array_is_list($values)) {
            throw self::invalid($key, 'a list of non-empty strings', $values);
        }

        $strings = [];

        foreach ($values as $value) {
            if (! is_string($value) || trim($value) === '') {
                throw self::invalid($key, 'a list of non-empty strings', $value);
            }

            $strings[] = $value;
        }

        return $strings;
    }

    private static function invalid(string $key, string $expectation, mixed $value): InvalidConfigurationException
    {
        $given = match (true) {
            $value === '' => "''",
            is_string($value) => $value,
            is_int($value), is_float($value), is_bool($value) => var_export($value, true),
            default => get_debug_type($value),
        };

        return new InvalidConfigurationException("Configuration value [{$key}] must be {$expectation}, [{$given}] given.");
    }
}
