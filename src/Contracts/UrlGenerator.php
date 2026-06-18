<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Contracts;

use RoundlyConsulting\MediaLibrary\Models\Media;

interface UrlGenerator
{
    /** Public URL for the media's original (or a variant, in later phases). */
    public function getUrl(Media $media): string;
}
