<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use RoundlyConsulting\MediaLibrary\DataTransferObjects\FetchOptions;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\RemoteResponse;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFetchFailed;
use RoundlyConsulting\MediaLibrary\Support\RemoteFileFetcher;

/**
 * Fetches a user-supplied URL server-side without letting it reach the host's own network —
 * `MediaLibrary::remote()->fetch()`.
 *
 * Every hop is canonicalised, vetted (private, loopback, link-local and metadata destinations
 * refused), pinned to the vetted addresses and re-vetted after a redirect; bytes, time and
 * redirects are capped. A 2xx answer comes back as a {@see RemoteResponse}; anything else throws a
 * {@see RemoteFetchFailed} whose `retryable()` says whether trying again can help.
 */
final readonly class FetchRemoteUrlAction
{
    public function __construct(
        private RemoteFileFetcher $fetcher,
    ) {}

    /**
     * @throws RemoteFetchFailed
     */
    public function execute(string $url, FetchOptions $options = new FetchOptions): RemoteResponse
    {
        return $this->fetcher->fetch($url, $options);
    }
}
