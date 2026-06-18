<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * Fired after a draft media row has been bound to an owning model — its `model_type`/`model_id`
 * are set and its draft token/expiry are cleared.
 */
final class DraftMediaHasBeenBound
{
    use Dispatchable;

    public function __construct(
        public readonly Media $media,
    ) {}
}
