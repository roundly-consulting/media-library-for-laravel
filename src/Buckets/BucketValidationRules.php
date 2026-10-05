<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Buckets;

use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\MediaLibrary\Support\ExifOrientation;
use RoundlyConsulting\MediaLibrary\Support\MediaConfig;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Derives a Laravel validation rules array from a media bucket's declared constraints
 * (accepted mime types, max file size, min/max dimensions) — a single source of truth so the
 * rules follow whenever the bucket definition changes (§6.8). The rules never accept a file the
 * add would then refuse: the size is the exact byte cap, and dimensions are checked by the
 * package's own `media_dimensions` rule, the way {@see BucketGuard} measures them.
 *
 * Only constraints the bucket actually declares emit a rule; undeclared constraints are omitted —
 * except the size cap, which falls back to the package-level `media.max_file_size` default.
 */
final class BucketValidationRules
{
    /** The rule a bucket's dimension bounds derive to — registered by the service provider. */
    public const string DIMENSIONS_RULE = 'media_dimensions';

    /**
     * Build the rules array for `$bucket` on `$modelClass`.
     *
     * @param  class-string  $modelClass
     * @return list<string>
     */
    public function forModel(string $modelClass, string $bucket = 'default'): array
    {
        $definition = $this->resolveBucket($modelClass, $bucket);

        return $this->fromBucket($definition);
    }

    /**
     * @return list<string>
     */
    public function fromBucket(?MediaBucket $bucket): array
    {
        $rules = ['file'];

        $mimeTypes = $bucket?->getAcceptedMimeTypes() ?? [];

        if ($mimeTypes !== []) {
            $rules[] = 'mimetypes:'.implode(',', $mimeTypes);
        }

        $maxFileSize = self::maxFileSizeFor($bucket);

        if ($maxFileSize !== null) {
            // Laravel's `max` rule on files is in kilobytes, compared as a decimal: the exact
            // figure, never rounded up past the byte cap the guard enforces.
            $rules[] = 'max:'.self::kilobytes($maxFileSize);
        }

        if ($bucket === null) {
            return $rules;
        }

        $dimensions = $this->dimensionRule($bucket);

        if ($dimensions !== null) {
            $rules[] = $dimensions;
        }

        return $rules;
    }

    /**
     * The size cap, in bytes, a file entering `$bucket` must respect: the bucket's own
     * `maxFileSize()`, else the package-level `media.max_file_size`; null when neither sets one.
     * The same figure feeds the derived `max:` rule and the add-time check, so they never disagree.
     *
     * @internal
     */
    public static function maxFileSizeFor(?MediaBucket $bucket): ?int
    {
        $own = $bucket?->getMaxFileSize();

        if ($own !== null) {
            return $own;
        }

        return MediaConfig::maxFileSize();
    }

    private function dimensionRule(MediaBucket $bucket): ?string
    {
        $constraints = [];

        $min = $bucket->getMinDimensions();

        if ($min !== null) {
            $constraints[] = "min_width={$min[0]}";
            $constraints[] = "min_height={$min[1]}";
        }

        $max = $bucket->getMaxDimensions();

        if ($max !== null) {
            $constraints[] = "max_width={$max[0]}";
            $constraints[] = "max_height={$max[1]}";
        }

        if ($constraints === []) {
            return null;
        }

        return self::DIMENSIONS_RULE.':'.implode(',', $constraints);
    }

    /**
     * The `media_dimensions` rule: the size a viewer sees (EXIF orientation applied) within the
     * bounds, exactly as the guard measures it. Unlike Laravel's `dimensions`, an image whose size
     * cannot be read (an SVG) fails; a file that is no image passes, as the guard lets it in.
     *
     * @internal registered by the service provider
     *
     * @param  array<int, string>  $parameters  `min_width=…`, `min_height=…`, `max_width=…`, `max_height=…`
     */
    public static function passesDimensions(mixed $value, array $parameters): bool
    {
        $path = $value instanceof File ? $value->getRealPath() : false;

        if ($path === false || ! is_file($path)) {
            return false;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = $finfo === false ? false : finfo_file($finfo, $path);

        if ($finfo !== false) {
            finfo_close($finfo);
        }

        if (! str_starts_with((string) $mimeType, 'image/')) {
            return true;
        }

        $bounds = [];

        foreach ($parameters as $parameter) {
            [$name, $bound] = array_pad(explode('=', $parameter, 2), 2, '0');
            $bounds[$name] = (int) $bound;
        }

        $dimensions = ExifOrientation::displayDimensions($path);

        return BucketGuard::fitsDimensions(
            isset($bounds['min_width']) || isset($bounds['min_height']) ? [$bounds['min_width'] ?? 0, $bounds['min_height'] ?? 0] : null,
            isset($bounds['max_width']) || isset($bounds['max_height']) ? [$bounds['max_width'] ?? PHP_INT_MAX, $bounds['max_height'] ?? PHP_INT_MAX] : null,
            $dimensions[0] ?? null,
            $dimensions[1] ?? null,
        );
    }

    /** `$bytes` in kilobytes, exactly (a division by 1024 is exact in binary), without trailing zeros. */
    private static function kilobytes(int $bytes): string
    {
        return rtrim(rtrim(sprintf('%.10F', $bytes / 1024), '0'), '.');
    }

    /**
     * @param  class-string  $modelClass
     */
    private function resolveBucket(string $modelClass, string $bucket): ?MediaBucket
    {
        $instance = new $modelClass;

        if (! $instance instanceof HasMedia) {
            return null;
        }

        return $instance->resolveMediaBucket($bucket);
    }
}
