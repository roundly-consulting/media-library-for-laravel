<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use Carbon\CarbonImmutable;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;

/**
 * Deletes expired, never-bound draft media (rows + files) through the refcount-guarded
 * {@see DeleteMediaAction}, returning how many were pruned.
 *
 * Only rows that still carry a `draft_token` and whose `draft_expires_at` is in the past are
 * touched — bound media (token cleared) and unexpired drafts are left entirely alone.
 */
final class PruneDraftsAction
{
    public function __construct(
        private readonly DeleteMediaAction $deleteMedia,
    ) {}

    public function execute(): int
    {
        $pruned = 0;

        // Keyset iteration: deleting while paging by offset (`each()`) skips every other chunk.
        MediaModel::query()
            ->drafts()
            ->where('draft_expires_at', '<', CarbonImmutable::now())
            ->lazyById()
            ->each(function (Media $media) use (&$pruned): void {
                $this->deleteMedia->execute($media);
                $pruned++;
            });

        return $pruned;
    }
}
