<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

final class TemporaryUrlNotSupported extends MediaLibraryException
{
    public static function forDisk(string $disk): self
    {
        return new self(
            "The [{$disk}] disk cannot produce a temporary URL and the signed streaming route is disabled. ".
            'Enable the stream route (media.stream.enabled) or use a disk that supports temporaryUrl().'
        );
    }
}
