<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Contracts\UrlGenerator;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * Resolves a media's public URL straight from Laravel's filesystem.
 *
 * Variant URLs, private temporary URLs, and the signed streaming fallback are layered on in
 * later phases; for now this returns the original's public disk URL.
 */
final class DefaultUrlGenerator implements UrlGenerator
{
    public function getUrl(Media $media): string
    {
        return Storage::disk($media->disk)->url($media->getPath());
    }
}
