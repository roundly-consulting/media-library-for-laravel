<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * Fired after a media's underlying original has been replaced in place — its `id`, `uuid`, and
 * public URL are unchanged, but its bytes, checksum, dimensions, placeholders, and variants are
 * regenerated from the new file.
 */
final class MediaHasBeenReplaced
{
    use Dispatchable;

    public function __construct(
        public readonly Media $media,
    ) {}
}
