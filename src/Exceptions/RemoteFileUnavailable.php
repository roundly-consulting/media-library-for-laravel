<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

use RoundlyConsulting\MediaLibrary\Enums\RemoteFetchReason;

/**
 * A remote URL could not be fetched this time — the same call may succeed later: the server
 * answered 5xx, 408 or 429 (with `retryAfter()` when it said how long to wait), the connection
 * failed or timed out, or the host did not resolve.
 */
final class RemoteFileUnavailable extends RemoteFetchFailed
{
    public static function unresolvable(string $url): self
    {
        return (new self('The host of the remote URL ['.self::describe($url).'] could not be resolved.'))
            ->classify(RemoteFetchReason::Unresolved);
    }

    public static function failedStatus(string $url, int $status, ?int $retryAfter = null): self
    {
        return (new self('The remote file ['.self::describe($url)."] answered HTTP {$status}."))
            ->classify(RemoteFetchReason::Status, $status, $retryAfter);
    }

    public static function connectionFailed(string $url, ?int $curlError = null): self
    {
        return (new self(
            'The remote file ['.self::describe($url).'] could not be reached'
            .($curlError === null ? '.' : " (cURL error {$curlError}).")
        ))->classify(RemoteFetchReason::Connection);
    }

    public static function timedOut(string $url, int|float $seconds): self
    {
        return (new self('The remote file ['.self::describe($url).'] did not answer within '.round($seconds, 3).' seconds.'))
            ->classify(RemoteFetchReason::Timeout);
    }

    public function retryable(): bool
    {
        return true;
    }

    protected function defaultReason(): RemoteFetchReason
    {
        return RemoteFetchReason::Connection;
    }
}
