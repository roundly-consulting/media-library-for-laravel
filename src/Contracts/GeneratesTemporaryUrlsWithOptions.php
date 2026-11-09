<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Contracts;

use DateTimeInterface;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\TemporaryUrlOptions;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * A {@see UrlGenerator} that can ask the storage origin for response headers on a temporary URL —
 * what `Media::getTemporaryUrlWithOptions()` and `getTemporaryDownloadUrl()` need. Kept apart from
 * `UrlGenerator` so a generator written against that contract keeps working unchanged.
 */
interface GeneratesTemporaryUrlsWithOptions
{
    /**
     * A time-limited URL whose response carries `$options`' disposition and content type: the
     * presigned URL's `response-content-*` overrides on S3 and S3-compatible disks, the signed
     * streaming route's rules (and `?download=1` for an attachment) elsewhere.
     */
    public function getTemporaryUrlWithOptions(Media $media, DateTimeInterface $expiry, TemporaryUrlOptions $options, string $variant = ''): string;
}
