<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Contracts;

use DateTimeInterface;
use RoundlyConsulting\MediaLibrary\Models\Media;

interface UrlGenerator
{
    /**
     * Public URL for the media's original (or a named variant).
     *
     * Throws when the media is private — private media has no public URL; use a temporary URL.
     */
    public function getUrl(Media $media, string $variant = ''): string;

    /**
     * A time-limited URL for the media's original (or a named variant).
     *
     * Returns the disk's native presigned URL when the driver supports it, otherwise a Laravel
     * signed streaming-route URL.
     */
    public function getTemporaryUrl(Media $media, DateTimeInterface $expiry, string $variant = ''): string;
}
