<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

use Closure;

/**
 * Resolves a host name to its addresses — IPv4 through the system resolver (hosts file
 * included), IPv6 through its AAAA records — once, so `addFromUrl()` can vet them and pin the
 * connection to the one it vetted.
 *
 * @internal
 */
final class HostResolver
{
    /**
     * @param  (Closure(string): list<string>)|null  $lookup  replaces DNS (testing seam)
     */
    public function __construct(
        private readonly ?Closure $lookup = null,
    ) {}

    /** @return list<string> every address the host resolves to; empty when it does not resolve */
    public function resolve(string $host): array
    {
        if ($this->lookup !== null) {
            return ($this->lookup)($host);
        }

        $addresses = @gethostbynamel($host) ?: [];

        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (is_string($record['ipv6'] ?? null)) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
