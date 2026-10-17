<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\DataTransferObjects;

use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFileRejected;

/**
 * A URL the guard accepted — `MediaLibrary::remote()->vet($url)`.
 *
 * `url` is what a fetch requests: rebuilt from the vetted parts (scheme and host lowercased, a
 * trailing dot and a default port dropped, the fragment cut), so no parser can read a different
 * host into it. `addresses` are the vetted addresses a connection must use: every address the
 * host resolved to, the address itself for an address literal, none for a host name trusted by
 * `media.remote.allowed_private_hosts`. To make the request yourself, pass `httpOptions()` to
 * Laravel's HTTP client: it pins those addresses and keeps the request off the proxy.
 */
final readonly class VettedUrl
{
    /**
     * @param  list<string>  $addresses
     */
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public array $addresses,
    ) {}

    /**
     * The `CURLOPT_RESOLVE` entry pinning `host:port` to every vetted address (curl 7.59+); null
     * when there is nothing to pin (an address literal, a trusted host name). On its own it is not
     * enough: a proxy from the environment resolves the name itself — use {@see self::httpOptions()}.
     */
    public function resolveEntry(): ?string
    {
        if ($this->addresses === [] || filter_var($this->host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $addresses = array_map(
            static fn (string $address): string => str_contains($address, ':') ? '['.$address.']' : $address,
            $this->addresses,
        );

        return $this->host.':'.$this->port.':'.implode(',', $addresses);
    }

    /**
     * The options that send a request of your own the way `MediaLibrary::remote()->fetch()` sends
     * its own: pinned to the vetted addresses, and never through the environment's proxy (which
     * would resolve the name again). For Laravel's HTTP client (Guzzle):
     *
     * ```php
     * Http::withOptions($vetted->httpOptions())->withoutRedirecting()->get($vetted->url);
     * ```
     *
     * Follow no redirect with it: a redirect is a new URL to vet.
     *
     * @return array<string, mixed>
     *
     * @throws RemoteFileRejected (`cannot_pin`) when there is something to pin and no ext-curl
     */
    public function httpOptions(): array
    {
        $entry = $this->resolveEntry();

        if ($entry === null) {
            return ['proxy' => ''];
        }

        // Without curl, Laravel's client cannot be told which addresses to use.
        if (! defined('CURLOPT_RESOLVE')) {
            throw RemoteFileRejected::cannotPin($this->url);
        }

        return ['proxy' => '', 'curl' => [CURLOPT_RESOLVE => [$entry]]];
    }
}
