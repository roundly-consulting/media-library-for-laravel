<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

/** `addFromUrl()` refused a remote source before storing anything. */
final class RemoteFileRejected extends MediaLibraryException
{
    public static function unsupportedScheme(string $url): self
    {
        return new self("Only http and https URLs can be added as media, [{$url}] given.");
    }

    public static function tooLarge(string $url, int $maxBytes): self
    {
        return new self("The remote file [{$url}] is larger than the {$maxBytes}-byte limit.");
    }
}
