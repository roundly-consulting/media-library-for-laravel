<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use RoundlyConsulting\MediaLibrary\Events\MediaHasBeenDeleted;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Observers\MediaObserver;

/**
 * Permanently deletes a {@see Media}: removes its row and its stored files (original + variants).
 *
 * File removal is delegated to {@see MediaObserver}
 * via `forceDelete()`, so the cleanup logic lives in exactly one place — a soft delete still
 * keeps the files, an explicit delete (or any `forceDelete`) removes them.
 *
 * The observer refcount-guards the original (§7.1): a shared deduplicated file survives until the
 * last referrer is deleted; this row's own variants are always removed.
 */
final class DeleteMediaAction
{
    public function execute(Media $media): void
    {
        $media->forceDelete();

        event(new MediaHasBeenDeleted($media));
    }
}
