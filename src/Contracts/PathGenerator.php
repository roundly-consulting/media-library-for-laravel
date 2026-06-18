<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Contracts;

use RoundlyConsulting\MediaLibrary\Models\Media;

interface PathGenerator
{
    /** Directory (relative to the disk root) holding the original file, with a trailing slash. */
    public function getPath(Media $media): string;

    /** Directory (relative to the disk root) holding generated variants, with a trailing slash. */
    public function getPathForVariants(Media $media): string;
}
