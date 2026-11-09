<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Contracts;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * Which rows a new or replaced original may share a stored file with (`media.deduplicate_scope`).
 *
 * Dedup finds rows that already store identical bytes on the same disk with the same visibility.
 * A scope narrows those candidates: only a row inside the scope is ever shared, so in a
 * multi-tenant application one tenant's media never points at a file another tenant's upload
 * wrote. The package re-runs the scope on the chosen row under its row lock, so a row that left
 * the scope in between is not shared.
 */
interface DeduplicationScope
{
    /**
     * Narrow `$candidates` to the rows `$media` may share an original with, or return null when
     * `$media` must store its own copy.
     *
     * `$media` is the row being stored — not saved yet on an add, so it has no key — with its
     * owner (`model_type`, `model_id`), bucket, disk, visibility and draft token set.
     *
     * @param  Builder<Media>  $candidates
     * @return Builder<Media>|null
     */
    public function constrain(Builder $candidates, Media $media): ?Builder;
}
