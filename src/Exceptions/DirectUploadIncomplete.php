<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

/**
 * The upload is not there (yet) — the file was never sent, a multipart upload is not complete, or
 * another request is adopting it right now. The ticket stays usable: try again.
 */
final class DirectUploadIncomplete extends MediaLibraryException
{
    public static function objectMissing(): self
    {
        return new self('The uploaded file is not in storage yet.');
    }

    public static function inProgress(): self
    {
        return new self('This direct upload is being adopted by another request.');
    }

    public static function partsMissing(): self
    {
        return new self('The multipart upload does not hold the parts it was completed with.');
    }
}
