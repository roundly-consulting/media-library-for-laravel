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

            foreach ($this->responsiveVariants($bucket, $media) as $variant) {
                $definitions[] = $variant;
            }
        }

        foreach ($this->modelLevelVariants($owner, $media, $bucketName) as $variant) {
            $definitions[] = $variant;
        }

        return $definitions;
    }

    /**
     * Synthesize one width-named variant per responsive ladder width, skipping any width larger
     * than the original (never upscale) once the media's pixel width is known.
     *
     * @return list<Variant>
     */
    private function responsiveVariants(MediaBucket $bucket, ?Media $media): array
    {
        if (! $bucket->hasResponsiveWidths()) {
            return [];
        }

        $originalWidth = $media?->width;

        $variants = [];

        foreach ($bucket->getResponsiveWidths() as $width) {
            if (is_int($originalWidth) && $originalWidth > 0 && $width > $originalWidth) {
                continue;
            }

            $variant = new Variant(ResponsiveImageGenerator::variantName($width));
            $variant->width($width)->fit('contain');

            $format = $bucket->getResponsiveFormat();

            if ($format !== null) {
                $variant->format($format);
            }

            $variants[] = $variant;
        }

        return $variants;
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
