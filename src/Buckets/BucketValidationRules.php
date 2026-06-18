<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Buckets;

use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;

/**
 * Derives a Laravel validation rules array from a media bucket's declared constraints
 * (accepted mime types, max file size, min/max dimensions) — a single source of truth so the
 * rules follow whenever the bucket definition changes (§6.8).
 *
 * Only constraints the bucket actually declares emit a rule; undeclared constraints are omitted.
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

        if ($bucket === null) {
            return $rules;
        }

        $mimeTypes = $bucket->getAcceptedMimeTypes();

        if ($mimeTypes !== []) {
            $rules[] = 'mimetypes:'.implode(',', $mimeTypes);
        }

        $maxFileSize = $bucket->getMaxFileSize();

        if ($maxFileSize !== null) {
            // Laravel's `max` rule on files is expressed in kilobytes.
            $rules[] = 'max:'.(int) ceil($maxFileSize / 1024);
        }

        $dimensions = $this->dimensionRule($bucket);

        if ($dimensions !== null) {
            $rules[] = $dimensions;
        }

        return $rules;
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
