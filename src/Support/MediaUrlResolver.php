<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RoundlyConsulting\MediaLibrary\Exceptions\MediaCannotBeStreamed;
use RoundlyConsulting\MediaLibrary\Exceptions\TemporaryUrlNotSupported;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RuntimeException;

/**
 * Resolves a media's public and temporary URLs (§9.1).
 *
 * Public URLs come straight from the disk. Temporary URLs use the disk's native presigning
 * (S3 and friends) when available, otherwise degrade to a Laravel signed streaming-route URL.
 * The strategy is one method regardless of driver, so callers never branch on the disk.
 */
final class MediaUrlResolver
{
    public const ROUTE_NAME = 'media.stream';

    public function publicUrl(Media $media, string $variant = ''): string
    {
        if ($media->isPrivate()) {
            throw MediaCannotBeStreamed::noPublicUrl();
        }

        return $this->disk($media, $variant)->url($media->getPath($variant));
    }

    public function temporaryUrl(Media $media, DateTimeInterface $expiry, string $variant = ''): string
    {
        $native = $this->nativeTemporaryUrl($media, $expiry, $variant);

        if ($native !== null) {
            return $native;
        }

        return $this->signedRouteUrl($media, $expiry, $variant);
    }

    /**
     * Native presigned URL, or null when the disk driver cannot produce one.
     */
    private function nativeTemporaryUrl(Media $media, DateTimeInterface $expiry, string $variant): ?string
    {
        try {
            return $this->disk($media, $variant)->temporaryUrl($media->getPath($variant), $expiry);
        } catch (RuntimeException) {
            // Local/cold drivers throw "This driver does not support creating temporary URLs."
            // Degrade to the signed streaming route — never leak the exception to the caller.
            return null;
        }
    }

    private function signedRouteUrl(Media $media, DateTimeInterface $expiry, string $variant): string
    {
        if (config('media.stream.enabled') !== true) {
            throw TemporaryUrlNotSupported::forDisk($media->diskFor($variant));
        }

        return URL::temporarySignedRoute(self::ROUTE_NAME, $expiry, [
            'media' => $media->uuid,
            'variant' => $variant,
        ]);
    }

    private function disk(Media $media, string $variant): Filesystem
    {
        return Storage::disk($media->diskFor($variant));
    }
}
