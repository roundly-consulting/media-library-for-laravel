<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

/** The ticket was issued for one actor (`forActor()`) and another — or none — tried to use it. */
final class DirectUploadActorMismatch extends MediaLibraryException
{
    public static function make(): self
    {
        return new self('This direct upload was issued for another actor.');
    }
}
