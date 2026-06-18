<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

final class FileDoesNotExist extends MediaLibraryException
{
    public static function forPath(string $path): self
    {
        return new self("The file at path [{$path}] does not exist or is not readable.");
    }

    public static function onDisk(string $path, string $disk): self
    {
        return new self("The file [{$path}] does not exist on disk [{$disk}].");
    }
}
