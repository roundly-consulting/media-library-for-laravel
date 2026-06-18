<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

final class FileUnacceptableForBucket extends MediaLibraryException
{
    public static function mimeType(string $mimeType, string $bucket): self
    {
        return new self(
            "The mime type [{$mimeType}] is not accepted by media bucket [{$bucket}]."
        );
    }
}
