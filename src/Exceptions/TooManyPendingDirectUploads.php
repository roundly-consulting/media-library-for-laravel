<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

use Illuminate\Database\Eloquent\Model;

/**
 * The owner (or, for a global upload or a draft, the actor) already holds
 * `media.direct_uploads.max_pending_per_owner` pending tickets — nothing was signed.
 *
 * Extends {@see DirectUploadsNotSupported}, the type thrown before 2.2, so existing catches keep
 * catching it. The message names neither the holder nor its key.
 */
final class TooManyPendingDirectUploads extends DirectUploadsNotSupported
{
    /**
     * @param  int  $limit  the configured cap
     * @param  Model|null  $holder  the model the cap counted against: the owner, or the actor
     */
    public function __construct(
        string $message,
        public readonly int $limit,
        public readonly ?Model $holder = null,
    ) {
        parent::__construct($message);
    }

    public static function make(int $limit, ?Model $holder = null): self
    {
        return new self("There are already {$limit} pending direct uploads for this owner.", $limit, $holder);
    }
}
