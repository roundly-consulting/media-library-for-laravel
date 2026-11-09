<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Deduplication;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\MediaLibrary\Contracts\DeduplicationScope;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * `media.deduplicate_scope` = `owner`: an original is shared only with media of the same owning
 * model (same `model_type` and `model_id`, any bucket).
 *
 * Global media (no owner) share only with other global media. A draft never shares: it has no
 * owner until it is bound, so whose file it would point at cannot be known.
 */
final class OwnerDeduplicationScope implements DeduplicationScope
{
    public function constrain(Builder $candidates, Media $media): ?Builder
    {
        if ($media->draft_token !== null) {
            return null;
        }

        if ($media->model_type === null || $media->model_id === null) {
            return $candidates->whereNull('model_type')->whereNull('model_id')->whereNull('draft_token');
        }

        return $candidates->where('model_type', $media->model_type)->where('model_id', $media->model_id);
    }
}
