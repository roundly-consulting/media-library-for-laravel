<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

final class InvalidBase64Data extends MediaLibraryException
{
    public static function make(): self
    {
        return new self('The provided string is not valid base64-encoded data.');
    }
}
