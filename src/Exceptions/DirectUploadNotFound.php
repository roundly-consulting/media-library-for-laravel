<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

/**
 * No live direct-upload ticket answers the token — unknown, malformed, or already rejected or
 * aborted. The message never carries the token.
 */
final class DirectUploadNotFound extends MediaLibraryException
{
    public static function forToken(): self
    {
        return new self('No direct upload matches this token, or it was rejected or aborted.');
    }
}
