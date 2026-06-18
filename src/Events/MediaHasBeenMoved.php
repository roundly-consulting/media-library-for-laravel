<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * Fired after a media's original (and its variants) have been relocated — across disks, models,
 * and/or buckets — and the row has been re-pointed and persisted.
 */
final class MediaHasBeenMoved
{
    use Dispatchable;

    public function __construct(
        public readonly Media $media,
    ) {}
}
