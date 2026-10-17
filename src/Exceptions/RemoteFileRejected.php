<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

use RoundlyConsulting\MediaLibrary\Enums\RemoteFetchReason;

/**
 * A remote URL was refused for good — retrying the same call cannot help: the URL itself is
 * unacceptable (scheme, user info, a non-canonical host, a blocked destination or port), the
 * server refused it (a 4xx other than 408/429), the answer is too large or redirects too often,
 * or its TLS certificate does not verify.
 *
 * `addFromUrl()` throws it before storing anything (a host it cannot resolve included, as it
 * always has); `MediaLibrary::remote()` reports an unresolvable host as
 * {@see RemoteFileUnavailable} instead, because DNS can heal.
 */
final class RemoteFileRejected extends RemoteFetchFailed
{
    public static function invalidUrl(string $url): self
    {
        return (new self('The remote URL ['.self::describe($url).'] is not an absolute http or https URL.'))
            ->classify(RemoteFetchReason::InvalidUrl);
    }

    public static function unsupportedScheme(string $url): self
    {
        return (new self('Only http and https URLs can be fetched or added as media, ['.self::describe($url).'] given.'))
            ->classify(RemoteFetchReason::Scheme);
    }

    public static function credentials(string $url): self
    {
        return (new self('The remote URL ['.self::describe($url).'] carries a user name or password, which is never sent.'))
            ->classify(RemoteFetchReason::Credentials);
    }

    public static function nonCanonicalHost(string $url): self
    {
        return (new self(
            'The host of the remote URL ['.self::describe($url).'] is not in canonical form: '
            .'a dotted-quad IPv4 address, a bracketed IPv6 address or a domain name.'
        ))->classify(RemoteFetchReason::NonCanonicalHost);
    }

    public static function internationalHostNeedsIntl(string $url): self
    {
        return (new self(
            'The host of the remote URL ['.self::describe($url).'] is an internationalised domain name, '
            .'which can only be checked with ext-intl installed.'
        ))->classify(RemoteFetchReason::NonCanonicalHost);
    }

    public static function privateAddress(string $url): self
    {
        return (new self(
            'The remote file ['.self::describe($url).'] points at a private, loopback, link-local or reserved network address. '
            .'Allow trusted internal hosts with media.remote.allowed_private_hosts.'
        ))->classify(RemoteFetchReason::Blocked);
    }

    public static function blockedPort(string $url): self
    {
        return (new self('The remote URL ['.self::describe($url).'] uses a port that is not allowed (media.remote.allowed_ports).'))
            ->classify(RemoteFetchReason::Port);
    }

    public static function unresolvableHost(string $url): self
    {
        return (new self('The host of the remote file ['.self::describe($url).'] could not be resolved.'))
            ->classify(RemoteFetchReason::Unresolved);
    }

    public static function tooManyRedirects(string $url, int $maxRedirects): self
    {
        return (new self('The remote file ['.self::describe($url)."] redirected more than {$maxRedirects} times."))
            ->classify(RemoteFetchReason::TooManyRedirects);
    }

    public static function cannotPin(string $url): self
    {
        return (new self(
            'The remote file ['.self::describe($url).'] cannot be fetched safely: pinning the connection to the vetted address needs ext-curl.'
        ))->classify(RemoteFetchReason::CannotPin);
    }

    public static function tooLarge(string $url, int $maxBytes): self
    {
        return (new self('The remote file ['.self::describe($url)."] is larger than the {$maxBytes}-byte limit."))
            ->classify(RemoteFetchReason::TooLarge);
    }

    public static function failedStatus(string $url, int $status): self
    {
        return (new self('The remote file ['.self::describe($url)."] answered HTTP {$status}."))
            ->classify(RemoteFetchReason::Status, $status);
    }

    public static function tlsFailed(string $url, ?int $curlError = null): self
    {
        return (new self(
            'The TLS certificate of the remote file ['.self::describe($url).'] could not be verified'
            .($curlError === null ? '.' : " (cURL error {$curlError}).")
        ))->classify(RemoteFetchReason::Tls);
    }

    public function retryable(): bool
    {
        return false;
    }

    protected function defaultReason(): RemoteFetchReason
    {
        return RemoteFetchReason::Blocked;
    }
}
