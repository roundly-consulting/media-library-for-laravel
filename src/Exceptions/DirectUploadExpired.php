<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

/** The ticket's time ran out before it was adopted; its upload is pruned. Issue a new one. */
final class DirectUploadExpired extends MediaLibraryException
{
    public static function make(): self
    {
        return new self('This direct upload has expired. Issue a new one.');
    }
}
