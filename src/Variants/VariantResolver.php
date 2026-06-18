<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Variants;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\MediaLibrary\Buckets\MediaBucket;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * Resolves the {@see Variant} definitions that apply to a given media or bucket.
 *
 * Variants are declared inline on a bucket (`->registerVariants()`) and, optionally, on the
 * owning model's `registerMediaVariants()` hook with `->performOnBuckets()` targeting.
 */
final class VariantResolver
{
    /**
     * @return list<Variant>
     */
    public function forMedia(Media $media): array
    {
        $owner = $media->model;

        if (! $owner instanceof HasMedia) {
            return [];
        }

        return $this->forOwnerBucket($owner, $media->bucket_name, $media);
    }

    /**
     * @return list<Variant>
     */
    public function forOwnerBucket(HasMedia $owner, string $bucketName, ?Media $media = null): array
    {
        $bucket = $owner->resolveMediaBucket($bucketName);

        $definitions = [];

        if ($bucket instanceof MediaBucket) {
            $definitions = $bucket->variants()->all();
        }

        foreach ($this->modelLevelVariants($owner, $media, $bucketName) as $variant) {
            $definitions[] = $variant;
        }

        return $definitions;
    }

    /**
     * @return list<Variant>
     */
    private function modelLevelVariants(HasMedia $owner, ?Media $media, string $bucketName): array
    {
        if (! $owner instanceof Model || ! method_exists($owner, 'resolveModelMediaVariants')) {
            return [];
        }

        /** @var VariantCollection $collection */
        $collection = $owner->resolveModelMediaVariants($media);

        return $collection->forBucket($bucketName);
    }
}
