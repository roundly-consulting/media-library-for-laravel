<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

use RoundlyConsulting\MediaLibrary\Contracts\PathGenerator;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * Lays files out under the media's UUID so the auto-increment id never leaks into a path,
 * and collisions are impossible: `{uuid}/` for the original, `{uuid}/variants/` for derivatives.
 */
final class DefaultPathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        return $media->uuid.'/';
    }

    public function getPathForVariants(Media $media): string
    {
        return $media->uuid.'/variants/';
    }
}
