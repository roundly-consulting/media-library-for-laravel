<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

use SensitiveParameter;

final class DraftMediaNotFound extends MediaLibraryException
{
    public static function forToken(#[SensitiveParameter] string $token): self
    {
        return new self("No draft media exists for token [{$token}], or it has already been bound.");
    }
}
