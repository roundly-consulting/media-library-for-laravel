<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

final class DraftMediaExpired extends MediaLibraryException
{
    public static function forToken(string $token): self
    {
        return new self("Draft media for token [{$token}] has expired and can no longer be bound.");
    }
}
