<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

use DateTimeInterface;
use RoundlyConsulting\MediaLibrary\Contracts\UrlGenerator;
use RoundlyConsulting\MediaLibrary\Exceptions\InvalidVariant;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Builds media URLs from Laravel's filesystem, delegating the public/temporary strategy to the
 * MediaUrlResolver (§9.1). The CdnUrlGenerator (§9.4) is layered on top of this in a later phase.
 */
final class DefaultUrlGenerator implements UrlGenerator
{
    public function __construct(
        private readonly MediaUrlResolver $resolver,
    ) {}

    public function getUrl(Media $media, string $variant = ''): string
    {
        $variant = $this->resolveTargetVariant($media, $variant);

        return $this->resolver->publicUrl($media, $variant);
    }

    public function getTemporaryUrl(Media $media, DateTimeInterface $expiry, string $variant = ''): string
    {
        $variant = $this->resolveTargetVariant($media, $variant);

        return $this->resolver->temporaryUrl($media, $expiry, $variant);
    }

    /**
     * Resolve which variant a URL should actually target: the requested one when generated,
     * else either fall back to the original ('') or throw, per `media.url_fallback_to_original`.
     */
    private function resolveTargetVariant(Media $media, string $variant): string
    {
        if ($variant === '' || $media->hasGeneratedVariant($variant)) {
            return $variant;
        }

        if (Config::boolean('media.url_fallback_to_original')) {
            return '';
        }

        throw InvalidVariant::notGenerated($variant);
    }
}
