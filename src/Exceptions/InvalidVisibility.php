<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

/** A visibility other than `public` or `private` — never guessed, since a typo could publish a file. */
final class InvalidVisibility extends MediaLibraryException
{
    public static function given(string $visibility): self
    {
        return new self("Media visibility must be [public] or [private], [{$visibility}] given.");
    }
}
