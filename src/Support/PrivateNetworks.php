<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

/**
 * The addresses no untrusted URL may reach — `addFromUrl()` and `MediaLibrary::remote()`: private,
 * loopback, link-local (cloud metadata at 169.254.169.254), carrier-grade NAT, multicast,
 * documentation and reserved ranges, IPv4 and IPv6 alike, plus the host names that always mean the
 * machine itself. An IPv6 address that carries an IPv4 one (IPv4-mapped `::ffff:a.b.c.d`,
 * IPv4-compatible, NAT64, IPv4-translated, 6to4) is judged by the IPv4 address it carries.
 *
 * This table is a contract: `tests/Fixtures/contract/blocked-networks.json` holds it and its
 * vectors, byte-identical in the Node twin (safe-fetch-for-node), so both sides block the same
 * destinations. Change them together.
 *
 * Also matches addresses and host names against `media.remote.allowed_private_hosts`.
 *
 * @internal
 */
final class PrivateNetworks
{
    public const array BLOCKED = [
        '0.0.0.0/8',        // "this" network
        '10.0.0.0/8',       // private
        '100.64.0.0/10',    // carrier-grade NAT (Alibaba Cloud metadata at 100.100.100.200)
        '127.0.0.0/8',      // loopback
        '168.63.129.16/32', // Azure WireServer: public address space, reachable from every Azure VM and AKS node
        '169.254.0.0/16',   // link-local: AWS, GCP, Azure, OpenStack metadata at 169.254.169.254
        '172.16.0.0/12',    // private
        '192.0.0.0/24',     // IETF protocol assignments (Oracle Cloud metadata at 192.0.0.192)
        '192.0.2.0/24',     // documentation
        '192.88.99.0/24',   // 6to4 relay anycast
        '192.168.0.0/16',   // private
        '198.18.0.0/15',    // benchmarking
        '198.51.100.0/24',  // documentation
        '203.0.113.0/24',   // documentation
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved, broadcast
        '64:ff9b:1::/48',   // NAT64, local use
        '100::/64',         // discard
        '2001::/23',        // IETF protocol assignments (Teredo included)
        '2001:db8::/32',    // documentation
        '3fff::/20',        // documentation (RFC 9637)
        '5f00::/16',        // SRv6 SIDs (RFC 9602)
        'fc00::/7',         // unique local (AWS metadata over IPv6 at fd00:ec2::254)
        'fe80::/10',        // link-local
        'fec0::/10',        // site-local
        'ff00::/8',         // multicast
    ];

    /**
     * IPv6 prefixes that carry an IPv4 address, and the byte it starts at. The first match decides.
     *
     * @var list<array{range: string, offset: int}>
     */
    public const array IPV4_EMBEDDING = [
        ['range' => '::ffff:0:0/96', 'offset' => 12],    // IPv4-mapped
        ['range' => '::/96', 'offset' => 12],            // IPv4-compatible, incl. :: and ::1
        ['range' => '64:ff9b::/96', 'offset' => 12],     // NAT64
        ['range' => '::ffff:0:0:0/96', 'offset' => 12],  // IPv4-translated (RFC 2765)
        ['range' => '2002::/16', 'offset' => 2],         // 6to4: 2002:AABB:CCDD::/48 carries AA.BB.CC.DD
    ];

    /** Host names refused by spelling, before any lookup (a trailing dot stripped, any case). */
    public const array BLOCKED_HOSTNAMES = ['localhost'];

    /** Host name endings refused by spelling (RFC 6761: every `*.localhost` is loopback). */
    public const array BLOCKED_HOSTNAME_SUFFIXES = ['.localhost'];

    /**
     * Whether `$address` is one no untrusted fetch may reach. Anything unparseable counts as one;
     * an IPv6 zone id (`fe80::1%eth0`) is ignored, the address judged without it.
     */
    public static function isPrivate(string $address): bool
    {
        if (str_contains($address, ':') && str_contains($address, '%')) {
            $address = substr($address, 0, (int) strpos($address, '%'));
        }

        // PHP's own parser first, not only the platform's: BSD libc reads `0177.0.0.1` as an address.
        $bytes = filter_var($address, FILTER_VALIDATE_IP) === false ? false : @inet_pton($address);

        if ($bytes === false) {
            return true;
        }

        $embedded = self::embeddedIpv4($bytes);

        if ($embedded !== null) {
            return self::isPrivate($embedded);
        }

        foreach (self::BLOCKED as $range) {
            if (self::bytesInRange($bytes, $range)) {
                return true;
            }
        }

        return false;
    }

    /** Whether a host name always means the machine itself (`localhost`, `*.localhost`). */
    public static function isBlockedHostname(string $host): bool
    {
        $host = strtolower(str_ends_with($host, '.') ? substr($host, 0, -1) : $host);

        if (in_array($host, self::BLOCKED_HOSTNAMES, true)) {
            return true;
        }

        foreach (self::BLOCKED_HOSTNAME_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** Whether an allowlist entry is an address or a range of them, rather than a host name. */
    public static function isAddressOrRange(string $entry): bool
    {
        return @inet_pton(explode('/', $entry, 2)[0]) !== false;
    }

    /** Whether `$entry` is a well-formed `address/prefix` range. */
    public static function isValidRange(string $entry): bool
    {
        [$network, $prefix] = array_pad(explode('/', $entry, 2), 2, null);
        $bytes = @inet_pton((string) $network);

        return $bytes !== false && $prefix !== null && ctype_digit($prefix) && (int) $prefix <= strlen($bytes) * 8;
    }

    /**
     * Whether an allowlist entry names this host.
     *
     * @param  list<string>  $allowlist
     */
    public static function allowsHost(string $host, array $allowlist): bool
    {
        foreach ($allowlist as $entry) {
            if (! self::isAddressOrRange($entry) && strcasecmp($entry, $host) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether an allowlist address or range covers this address (or the IPv4 address it carries).
     *
     * @param  list<string>  $allowlist
     */
    public static function allowsAddress(string $address, array $allowlist): bool
    {
        $bytes = @inet_pton($address);

        if ($bytes === false) {
            return false;
        }

        $embedded = self::embeddedIpv4($bytes);
        $candidates = $embedded === null ? [$bytes] : [$bytes, (string) inet_pton($embedded)];

        foreach ($allowlist as $entry) {
            if (! self::isAddressOrRange($entry)) {
                continue;
            }

            $range = str_contains($entry, '/') ? $entry : $entry.'/'.(str_contains($entry, ':') ? 128 : 32);

            foreach ($candidates as $candidate) {
                if (self::bytesInRange($candidate, $range)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** The IPv4 address an IPv6 one carries, in dotted form; null when it carries none. */
    private static function embeddedIpv4(string $bytes): ?string
    {
        if (strlen($bytes) !== 16) {
            return null;
        }

        foreach (self::IPV4_EMBEDDING as $embedding) {
            if (self::bytesInRange($bytes, $embedding['range'])) {
                return (string) inet_ntop(substr($bytes, $embedding['offset'], 4));
            }
        }

        return null;
    }

    private static function bytesInRange(string $bytes, string $range): bool
    {
        [$network, $prefix] = explode('/', $range, 2);
        $networkBytes = @inet_pton($network);

        if ($networkBytes === false || strlen($networkBytes) !== strlen($bytes)) {
            return false;
        }

        $bits = (int) $prefix;
        $whole = intdiv($bits, 8);

        if (substr($bytes, 0, $whole) !== substr($networkBytes, 0, $whole)) {
            return false;
        }

        $rest = $bits % 8;

        if ($rest === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($bytes[$whole]) & $mask) === (ord($networkBytes[$whole]) & $mask);
    }
}
