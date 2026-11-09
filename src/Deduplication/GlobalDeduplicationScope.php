<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Deduplication;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\MediaLibrary\Contracts\DeduplicationScope;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * `media.deduplicate_scope` = `global` (the default): identical bytes on the same disk and
 * visibility are stored once, whoever uploaded them.
 *
 * In a multi-tenant application that means one tenant's row can point at a file another tenant's
 * upload wrote — under that tenant's path. Use `owner`, `path_prefix` or a scope of your own there.
 */
final class GlobalDeduplicationScope implements DeduplicationScope
{
    public function constrain(Builder $candidates, Media $media): Builder
    {
        return $candidates;
    }
}
