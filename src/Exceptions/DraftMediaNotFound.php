<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

final class DraftMediaNotFound extends MediaLibraryException
{
    public static function forToken(string $token): self
    {
        return new self("No draft media exists for token [{$token}], or it has already been bound.");
    }
}
