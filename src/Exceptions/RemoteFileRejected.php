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

    public static function privateAddress(string $url): self
    {
        return new self(
            "The remote file [{$url}] points at a private, loopback, link-local or reserved network address. "
            .'Allow trusted internal hosts with media.remote.allowed_private_hosts.'
        );
    }

    public static function unresolvableHost(string $url): self
    {
        return new self("The host of the remote file [{$url}] could not be resolved.");
    }

    public static function tooManyRedirects(string $url, int $maxRedirects): self
    {
        return new self("The remote file [{$url}] redirected more than {$maxRedirects} times.");
    }

    public static function cannotPin(string $url): self
    {
        return new self(
            "The remote file [{$url}] cannot be fetched safely: pinning the connection to the vetted address needs ext-curl."
        );
    }

    public static function tooLarge(string $url, int $maxBytes): self
    {
        return new self("The remote file [{$url}] is larger than the {$maxBytes}-byte limit.");
    }
}
