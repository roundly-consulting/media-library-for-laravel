<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

use RoundlyConsulting\MediaLibrary\Enums\RemoteFetchReason;

/**
 * A remote URL was refused or could not be fetched — by `MediaLibrary::remote()`, its validation
 * rule, or `addFromUrl()`.
 *
 * - {@see RemoteFileRejected} is terminal: retrying cannot help (a blocked address, a 404).
 * - {@see RemoteFileUnavailable} is retryable: the same call may succeed later (a 503, a timeout).
 *
 * Messages name the URL — with any user info masked — and never an address it resolved to: the
 * address would tell whoever supplied the URL about the internal network.
 */
abstract class RemoteFetchFailed extends MediaLibraryException
{
    private const int MAX_URL_LENGTH = 2048;

    private ?RemoteFetchReason $reason = null;

    private ?int $status = null;

    private ?int $retryAfter = null;

    /** Whether the same call may succeed later. */
    abstract public function retryable(): bool;

    /** Why it failed. */
    public function reason(): RemoteFetchReason
    {
        return $this->reason ?? $this->defaultReason();
    }

    /** The HTTP status the server answered, when that is why it failed. */
    public function status(): ?int
    {
        return $this->status;
    }

    /** Seconds the server asked to wait before retrying (`Retry-After`), when it said. */
    public function retryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /** The reason of an exception built without one of the named constructors. */
    abstract protected function defaultReason(): RemoteFetchReason;

    protected function classify(RemoteFetchReason $reason, ?int $status = null, ?int $retryAfter = null): static
    {
        $this->reason = $reason;
        $this->status = $status;
        $this->retryAfter = $retryAfter;

        return $this;
    }

    /**
     * A URL as a message shows it: user info masked, control characters replaced, very long URLs
     * shortened.
     *
     * A password may hold a `/`, `?`, `#` or `@`, and then the authority seems to end early
     * (`http://user:p#ss@host/`). So when the URL opens with `name:` and is not `host:port`, all of
     * it up to its last `@` is masked: a few characters too many at worst, never a password.
     * Otherwise user info is what comes before the last `@` of the authority.
     */
    protected static function describe(string $url): string
    {
        $authority = strpos($url, '://');

        if ($authority !== false) {
            $start = $authority + 3;
            $rest = substr($url, $start);
            $password = ! str_starts_with($rest, '[')
                && preg_match('/^[^\/?#@]*:/', $rest) === 1
                && preg_match('/^[^\/?#@:]*:[0-9]*([\/?#]|$)/', $rest) !== 1;
            $at = $password ? strrpos($rest, '@') : strrpos(substr($rest, 0, strcspn($rest, '/?#')), '@');

            if ($at !== false) {
                $url = substr($url, 0, $start).'***'.substr($rest, $at);
            }
        }

        $url = (string) preg_replace('/[\x00-\x1F\x7F]/', '?', $url);

        return strlen($url) > self::MAX_URL_LENGTH ? mb_strcut($url, 0, self::MAX_URL_LENGTH).'…' : $url;
    }
}
