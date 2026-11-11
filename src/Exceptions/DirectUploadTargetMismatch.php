<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

/**
 * `adoptUpload()` / `completeUpload()` named an `expectedOwner` or `expectedBucket` the ticket was
 * not issued for. The ticket is left untouched — still pending, still adoptable by the right
 * route. The message names neither the ticket's owner nor its bucket.
 */
final class DirectUploadTargetMismatch extends MediaLibraryException
{
    public const string OWNER = 'owner';

    public const string BUCKET = 'bucket';

    /**
     * @param  self::OWNER|self::BUCKET  $mismatched  which expectation the ticket failed
     */
    public function __construct(string $message, public readonly string $mismatched)
    {
        parent::__construct($message);
    }

    public static function owner(): self
    {
        return new self('This direct upload was issued for another owner.', self::OWNER);
    }

    public static function bucket(): self
    {
        return new self('This direct upload was issued for another bucket.', self::BUCKET);
    }
}
