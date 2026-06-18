<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * Fired after a media row has been permanently removed along with its stored files
 * (original + variants).
 */
final class MediaHasBeenDeleted
{
    use Dispatchable;

    public function __construct(
        public readonly Media $media,
    ) {}
}
