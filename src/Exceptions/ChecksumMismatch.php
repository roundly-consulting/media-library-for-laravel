<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

final class ChecksumMismatch extends MediaLibraryException
{
    public static function forMedia(string $uuid): self
    {
        return new self(
            "The stored file for media [{$uuid}] no longer matches its recorded checksum. ".
            'The file may be corrupted or was modified outside the media library.'
        );
    }
}
