<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Buckets;

use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;

/**
 * Derives a Laravel validation rules array from a media bucket's declared constraints
 * (accepted mime types, max file size, min/max dimensions) — a single source of truth so the
 * rules follow whenever the bucket definition changes (§6.8).
 *
 * Only constraints the bucket actually declares emit a rule; undeclared constraints are omitted —
 * except the size cap, which falls back to the package-level `media.max_file_size` default.
 */
final class BucketValidationRules
{
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
            // Laravel's `max` rule on files is expressed in kilobytes.
            $rules[] = 'max:'.(int) ceil($maxFileSize / 1024);
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

        $configured = config('media.max_file_size');

        return is_numeric($configured) && (int) $configured > 0 ? (int) $configured : null;
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

        return 'dimensions:'.implode(',', $constraints);
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
