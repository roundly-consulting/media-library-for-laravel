<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

/**
 * A disk refused a write the media library needed — a full bucket, a denied policy, an outage.
 * Thrown before any row points at the missing file and before any source file is removed.
 */
final class FileCannotBeWritten extends MediaLibraryException
{
    public static function toDisk(string $path, string $disk): self
    {
        return new self("The file [{$path}] could not be written to disk [{$disk}].");
    }
}
