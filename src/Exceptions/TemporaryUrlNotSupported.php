<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

use RoundlyConsulting\MediaLibrary\Contracts\GeneratesTemporaryUrlsWithOptions;

final class TemporaryUrlNotSupported extends MediaLibraryException
{
    public static function forDisk(string $disk): self
    {
        return new self(
            "The [{$disk}] disk cannot produce a temporary URL and the signed streaming route is disabled. ".
            'Enable the stream route (media.stream.enabled) or use a disk that supports temporaryUrl().'
        );
    }

    /** The bound URL generator cannot ask for response headers. */
    public static function withResponseOptions(string $generator): self
    {
        return new self(
            "The [{$generator}] URL generator cannot set response headers on a temporary URL. ".
            'Implement '.GeneratesTemporaryUrlsWithOptions::class.' on it.'
        );
    }
}
