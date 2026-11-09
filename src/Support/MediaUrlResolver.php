<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

use DateTimeInterface;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\TemporaryUrlOptions;
use RoundlyConsulting\MediaLibrary\Enums\ContentDisposition;
use RoundlyConsulting\MediaLibrary\Exceptions\MediaCannotBeStreamed;
use RoundlyConsulting\MediaLibrary\Exceptions\TemporaryUrlNotSupported;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\PackageToolkit\Support\Config;
use RuntimeException;

/**
 * Resolves a media's public and temporary URLs (§9.1).
 *
 * Public URLs come straight from the disk. Temporary URLs use the disk's native presigning
 * (S3 and friends) when available, otherwise degrade to a Laravel signed streaming-route URL.
 * The strategy is one method regardless of driver, so callers never branch on the disk.
 *
 * With response options — asked for, or `media.temporary_url_response_headers` on — a presigned
 * URL carries them as S3's `ResponseContentDisposition` / `ResponseContentType`, so the storage
 * origin answers like the streaming route would: an attachment unless the type is inline-safe.
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

    public function temporaryUrl(Media $media, DateTimeInterface $expiry, string $variant = '', ?TemporaryUrlOptions $options = null): string
    {
        if ($options === null && Config::boolean('media.temporary_url_response_headers')) {
            $options = new TemporaryUrlOptions;
        }

        $native = $this->nativeTemporaryUrl($media, $expiry, $variant, $options);

        if ($native !== null) {
            return $native;
        }

        return $this->signedRouteUrl($media, $expiry, $variant, $options);
    }

    /**
     * Native presigned URL, or null when the disk driver cannot produce one.
     */
    private function nativeTemporaryUrl(Media $media, DateTimeInterface $expiry, string $variant, ?TemporaryUrlOptions $options): ?string
    {
        $presign = $options === null ? [] : ResponseHeaders::presignOptions($media, $variant, $options);

        try {
            return $this->disk($media, $variant)->temporaryUrl($media->getPath($variant), $expiry, $presign);
        } catch (RuntimeException) {
            // Local/cold drivers throw "This driver does not support creating temporary URLs."
            // Degrade to the signed streaming route — never leak the exception to the caller.
            return null;
        }
    }

    /**
     * The streaming route applies the response rules itself; an attachment asked for is its
     * `?download=1`, under the asked-for file name. Both are part of the signature.
     */
    private function signedRouteUrl(Media $media, DateTimeInterface $expiry, string $variant, ?TemporaryUrlOptions $options): string
    {
        if (! Config::boolean('media.stream.enabled', true)) {
            throw TemporaryUrlNotSupported::forDisk($media->diskFor($variant));
        }

        $parameters = ['media' => $media->uuid, 'variant' => $variant];

        if ($options?->disposition === ContentDisposition::Attachment) {
            $parameters['download'] = 1;
        }

        if ($options?->fileName !== null) {
            $parameters['name'] = FileNames::sanitize($options->fileName);
        }

        return URL::temporarySignedRoute(self::ROUTE_NAME, $expiry, $parameters);
    }

    private function disk(Media $media, string $variant): Filesystem
    {
        return Storage::disk($media->diskFor($variant));
    }
}
