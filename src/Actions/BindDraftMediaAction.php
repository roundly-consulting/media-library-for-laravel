<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\MediaLibrary\Events\DraftMediaHasBeenBound;
use RoundlyConsulting\MediaLibrary\Exceptions\DraftMediaExpired;
use RoundlyConsulting\MediaLibrary\Exceptions\DraftMediaNotFound;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;

/**
 * Binds an unbound draft media (matched by its opaque token) to an owning model: sets the
 * polymorphic owner and bucket, clears the draft token/expiry, and persists.
 *
 * Throws {@see DraftMediaNotFound} when no draft matches the token and {@see DraftMediaExpired}
 * when the draft's TTL has lapsed.
 */
final class BindDraftMediaAction
{
    public function execute(Model $owner, string $token, string $bucket = 'default'): Media
    {
        $media = $this->findDraft($token);

        $this->guardNotExpired($media, $token);

        $media->model_type = $owner->getMorphClass();
        $media->model_id = $owner->getKey();
        $media->bucket_name = $bucket;
        $media->draft_token = null;
        $media->draft_expires_at = null;
        $media->save();

        event(new DraftMediaHasBeenBound($media));

        return $media;
    }

    private function findDraft(string $token): Media
    {

        $media = MediaModel::query()
            ->whereNotNull('draft_token')
            ->where('draft_token', $token)
            ->first();

        if (! $media instanceof Media) {
            throw DraftMediaNotFound::forToken($token);
        }

        return $media;
    }

    private function guardNotExpired(Media $media, string $token): void
    {
        $expiresAt = $media->draft_expires_at;

        if ($expiresAt instanceof CarbonInterface && $expiresAt->isPast()) {
            throw DraftMediaExpired::forToken($token);
        }
    }
}
