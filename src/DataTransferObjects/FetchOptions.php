<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\DataTransferObjects;

use InvalidArgumentException;

/**
 * Per-call limits of `MediaLibrary::remote()->fetch()`. Null takes the configured value
 * (`media.remote.fetch_max_bytes`, `timeout`, `max_redirects`); `accept` is sent as the `Accept`
 * header on every hop.
 */
final readonly class FetchOptions
{
    /**
     * @throws InvalidArgumentException for a cap below 1 byte or second, negative redirects, or an
     *                                  accept value that is blank or would break the header
     */
    public function __construct(
        public ?int $maxBytes = null,
        public ?int $timeout = null,
        public ?int $maxRedirects = null,
        public ?string $accept = null,
    ) {
        if ($maxBytes !== null && $maxBytes < 1) {
            throw new InvalidArgumentException("maxBytes must be at least 1, {$maxBytes} given.");
        }

        if ($timeout !== null && $timeout < 1) {
            throw new InvalidArgumentException("timeout must be at least 1 second, {$timeout} given.");
        }

        if ($maxRedirects !== null && $maxRedirects < 0) {
            throw new InvalidArgumentException("maxRedirects must be at least 0, {$maxRedirects} given.");
        }

        if ($accept !== null && (trim($accept) === '' || preg_match('/[\x00-\x1F\x7F]/', $accept) === 1)) {
            throw new InvalidArgumentException('accept must be a non-blank header value without control characters.');
        }
    }
}
