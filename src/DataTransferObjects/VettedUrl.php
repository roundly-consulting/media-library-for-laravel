<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\DataTransferObjects;

/**
 * A URL the guard accepted — `MediaLibrary::remote()->vet($url)`.
 *
 * `url` is what a fetch requests: rebuilt from the vetted parts (scheme and host lowercased, a
 * trailing dot and a default port dropped, the fragment cut), so no parser can read a different
 * host into it. `addresses` are the vetted addresses a connection must use: every address the
 * host resolved to, the address itself for an address literal, none for a host name trusted by
 * `media.remote.allowed_private_hosts`.
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
     * The `CURLOPT_RESOLVE` entry pinning `host:port` to every vetted address (curl 7.59+), for a
     * host that builds its own request; null when there is nothing to pin (an address literal, a
     * trusted host name). `MediaLibrary::remote()->fetch()` pins for you.
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
}
