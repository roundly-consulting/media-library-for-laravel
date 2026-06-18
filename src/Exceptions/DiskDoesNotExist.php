<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

final class DiskDoesNotExist extends MediaLibraryException
{
    public static function named(string $disk): self
    {
        return new self(
            "Disk [{$disk}] is not configured. Add it to config/filesystems.php before using it for media."
        );
    }
}
