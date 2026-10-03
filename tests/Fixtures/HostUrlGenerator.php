<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Tests\Fixtures;

use DateTimeInterface;
use RoundlyConsulting\MediaLibrary\Contracts\UrlGenerator;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * A host's own URL generator, the swap `media.url_generator` documents.
 */
final class HostUrlGenerator implements UrlGenerator
{
    public function getUrl(Media $media, string $variant = ''): string
    {
        return 'https://host.test/'.$media->uuid;
    }

    public function getTemporaryUrl(Media $media, DateTimeInterface $expiry, string $variant = ''): string
    {
        return 'https://host.test/signed/'.$media->uuid;
    }
}
