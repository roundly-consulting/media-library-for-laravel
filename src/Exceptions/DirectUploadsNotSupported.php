<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

/** Direct uploads are off, or the disk cannot do what the upload needs — nothing was signed. */
final class DirectUploadsNotSupported extends MediaLibraryException
{
    public static function disabled(): self
    {
        return new self('Direct uploads are disabled. Turn on media.direct_uploads.enabled.');
    }

    public static function forDisk(string $disk, string $what): self
    {
        return new self("The [{$disk}] disk cannot {$what}.");
    }

    public static function crossDisk(string $from, string $to): self
    {
        return new self("An upload on the [{$from}] disk cannot be moved to the [{$to}] disk inside storage.");
    }

    public static function pathNotAdoptable(string $path): self
    {
        return new self("[{$path}] is not under the staging prefix or an adoptable prefix, or a media already uses it.");
    }

    public static function tooManyParts(int $parts, int $max): self
    {
        return new self("The upload needs {$parts} parts, more than the {$max} allowed. Use a bigger part size.");
    }

    public static function tooManyPending(int $max): self
    {
        return new self("There are already {$max} pending direct uploads for this owner.");
    }
}
