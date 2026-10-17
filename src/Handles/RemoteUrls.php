<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Handles;

use Illuminate\Contracts\Container\Container;
use RoundlyConsulting\MediaLibrary\Actions\FetchRemoteUrlAction;
use RoundlyConsulting\MediaLibrary\Actions\VetRemoteUrlAction;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\FetchOptions;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\RemoteResponse;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\VettedUrl;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFetchFailed;
use RoundlyConsulting\MediaLibrary\Support\PrivateNetworks;

/**
 * Guarded server-side fetches of user-supplied URLs — `MediaLibrary::remote()`.
 *
 * The same guard `addFromUrl()` uses, without storing anything. Under `MediaLibrary::fake()` this
 * stays real: fetches go through Laravel's HTTP client, so `Http::fake()` answers them.
 */
final readonly class RemoteUrls
{
    public function __construct(
        private Container $container,
    ) {}

    /**
     * Fetch a URL: a 2xx answer, its body at most `$maxBytes`. Null options take the configured
     * `media.remote.fetch_max_bytes`, `timeout` (seconds, every hop included) and `max_redirects`;
     * `$accept` is sent as the `Accept` header.
     *
     * @throws RemoteFetchFailed `retryable()` says whether trying again can help
     */
    public function fetch(string $url, ?int $maxBytes = null, ?int $timeout = null, ?int $maxRedirects = null, ?string $accept = null): RemoteResponse
    {
        return $this->container->make(FetchRemoteUrlAction::class)
            ->execute($url, new FetchOptions($maxBytes, $timeout, $maxRedirects, $accept));
    }

    /**
     * The guard's verdict on a URL without fetching it: where it leads, or why not.
     *
     * @throws RemoteFetchFailed
     */
    public function vet(string $url): VettedUrl
    {
        return $this->container->make(VetRemoteUrlAction::class)->execute($url);
    }

    /** Whether no remote fetch may reach this address. Anything unparseable counts as private. */
    public function isPrivateAddress(string $address): bool
    {
        return PrivateNetworks::isPrivate($address);
    }
}
