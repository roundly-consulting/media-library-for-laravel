<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Tests\Fixtures;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\MediaLibrary\Contracts\DeduplicationScope;
use RoundlyConsulting\MediaLibrary\Models\Media;

/** A host's own scope: share only inside one bucket name, and never in the `vault` bucket. */
final class SameBucketDeduplicationScope implements DeduplicationScope
{
    public function constrain(Builder $candidates, Media $media): ?Builder
    {
        if ($media->bucket_name === 'vault') {
            return null;
        }

        return $candidates->where('bucket_name', $media->bucket_name);
    }
}
