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
 * Phase 5 will refcount-guard the underlying file removal so shared deduplicated files survive
 * until the last referrer is deleted.
 */
final class DeleteMediaAction
{
    public function execute(Media $media): void
    {
        $media->forceDelete();

        event(new MediaHasBeenDeleted($media));
    }
}
