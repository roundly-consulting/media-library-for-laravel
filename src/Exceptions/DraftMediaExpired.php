<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

use SensitiveParameter;

final class DraftMediaExpired extends MediaLibraryException
{
    public static function forToken(#[SensitiveParameter] string $token): self
    {
        return new self("Draft media for token [{$token}] has expired and can no longer be bound.");
    }
}
