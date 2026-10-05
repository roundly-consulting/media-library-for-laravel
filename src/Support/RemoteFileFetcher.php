<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFileRejected;

/**
 * Fetches the file behind an `addFromUrl()` URL through Laravel's HTTP client — so `Http::fake()`
 * still stands in for it — without letting the URL reach the host's own network.
 *
 * With `media.remote.block_private_networks` on (the default), every hop is vetted before it is
 * requested: the host is resolved once, every address it resolves to must be public (see
 * {@see PrivateNetworks}) unless `media.remote.allowed_private_hosts` allows it, and the
 * connection is pinned to the vetted address so a second DNS answer cannot swap in another.
 * Redirects are followed by hand, at most five, each one vetted the same way.
 *
 * @internal
 */
final class RemoteFileFetcher
{
    private const MAX_REDIRECTS = 5;

    public function __construct(
        private readonly HostResolver $resolver,
    ) {}

    /** @throws RemoteFileRejected when a hop is not http(s), does not resolve, or is private */
    public function get(string $url): Response
    {
        $this->ensureHttp($url);

        if (! MediaConfig::blockPrivateNetworks()) {
            return $this->client()->get($url);
        }

        $allowlist = MediaConfig::allowedPrivateHosts();

        for ($redirects = 0; ; $redirects++) {
            $pin = $this->vet($url, $allowlist);

            $request = $this->client()->withoutRedirecting();

            if ($pin !== null) {
                $request->withOptions(['curl' => [CURLOPT_RESOLVE => [$pin]]]);
            }

            $response = $request->get($url);
            $location = $response->redirect() ? trim($response->header('Location')) : '';

            if ($location === '') {
                return $response;
            }

            if ($redirects === self::MAX_REDIRECTS) {
                throw RemoteFileRejected::tooManyRedirects($url, self::MAX_REDIRECTS);
            }

            $url = self::resolveReference($url, $location);
            $this->ensureHttp($url);
        }
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders(MediaConfig::remoteHeaders())->timeout(MediaConfig::remoteTimeout());
    }

    private function ensureHttp(string $url): void
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw RemoteFileRejected::unsupportedScheme($url);
        }
    }

    /**
     * Check where `$url` leads. Returns the `host:port:address` pin for the connection, or null
     * when there is no name to pin (an address literal, an allowlisted host name).
     *
     * @param  list<string>  $allowlist
     */
    private function vet(string $url, array $allowlist): ?string
    {
        $host = strtolower(trim((string) parse_url($url, PHP_URL_HOST), '[]'));

        if ($host !== '' && PrivateNetworks::allowsHost($host, $allowlist)) {
            return null;
        }

        $literal = @inet_pton($host) !== false;
        $addresses = $literal ? [$host] : ($host === '' ? [] : $this->resolver->resolve($host));

        if ($addresses === []) {
            throw RemoteFileRejected::unresolvableHost($url);
        }

        foreach ($addresses as $address) {
            if (PrivateNetworks::isPrivate($address) && ! PrivateNetworks::allowsAddress($address, $allowlist)) {
                throw RemoteFileRejected::privateAddress($url);
            }
        }

        if ($literal) {
            return null;
        }

        // Without curl, Laravel's client cannot be told which address to use.
        if (! extension_loaded('curl')) {
            throw RemoteFileRejected::cannotPin($url);
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $port = parse_url($url, PHP_URL_PORT) ?? ($scheme === 'https' ? 443 : 80);
        $address = $addresses[0];

        return $host.':'.$port.':'.(str_contains($address, ':') ? '['.$address.']' : $address);
    }

    /** Resolve a redirect's `Location` against the URL it came from (RFC 3986 §5.2). */
    private static function resolveReference(string $base, string $reference): string
    {
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $reference) === 1) {
            return $reference;
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'http';
        $authority = ($parts['host'] ?? '');
        $authority = str_contains($authority, ':') && ! str_starts_with($authority, '[') ? '['.$authority.']' : $authority;
        $authority .= isset($parts['port']) ? ':'.$parts['port'] : '';

        if (str_starts_with($reference, '//')) {
            return $scheme.':'.$reference;
        }

        $path = $parts['path'] ?? '/';

        if (str_starts_with($reference, '?')) {
            return $scheme.'://'.$authority.$path.$reference;
        }

        if (! str_starts_with($reference, '/')) {
            $reference = substr($path, 0, (int) strrpos($path, '/') + 1).$reference;
        }

        return $scheme.'://'.$authority.self::removeDotSegments($reference);
    }

    private static function removeDotSegments(string $reference): string
    {
        $query = '';
        $cut = strcspn($reference, '?#');

        if ($cut < strlen($reference)) {
            $query = substr($reference, $cut);
            $reference = substr($reference, 0, $cut);
        }

        $output = [];

        foreach (explode('/', $reference) as $segment) {
            if ($segment === '..') {
                if (count($output) > 1) {
                    array_pop($output);
                }
            } elseif ($segment !== '.') {
                $output[] = $segment;
            }
        }

        $path = implode('/', $output);

        if (str_ends_with($reference, '/..') || str_ends_with($reference, '/.')) {
            $path .= '/';
        }

        return ($path === '' ? '/' : $path).$query;
    }
}
