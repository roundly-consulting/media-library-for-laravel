<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\MediaLibrary\Exceptions\FileDoesNotExist;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFileRejected;
use Throwable;

/**
 * Fetches the file behind an `addFromUrl()` URL through Laravel's HTTP client — so `Http::fake()`
 * still stands in for it — without letting the URL reach the host's own network, and without
 * holding the body in memory: it is streamed to a local file, cut off at the size cap.
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

    /**
     * Download `$url` into the local file at `$path`, streamed straight to disk. With `$maxBytes`
     * the transfer is cut off as soon as the body passes it, whatever the server declared.
     *
     * @throws RemoteFileRejected when a hop is not http(s), does not resolve, or is private, or
     *                            when the body passes `$maxBytes`
     */
    public function download(string $url, string $path, ?int $maxBytes): Response
    {
        $this->ensureHttp($url);

        if (! MediaConfig::blockPrivateNetworks()) {
            return $this->send($this->client(), $url, $path, $maxBytes);
        }

        $allowlist = MediaConfig::allowedPrivateHosts();

        for ($redirects = 0; ; $redirects++) {
            $pin = $this->vet($url, $allowlist);

            $request = $this->client()->withoutRedirecting();

            if ($pin !== null) {
                $request->withOptions(['curl' => [CURLOPT_RESOLVE => [$pin]]]);
            }

            $response = $this->send($request, $url, $path, $maxBytes);
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

    /** One request, its body written to `$path` (truncated first) through the size cap. */
    private function send(PendingRequest $request, string $url, string $path, ?int $maxBytes): Response
    {
        $limit = $maxBytes === null ? null : new SizeLimit($maxBytes);
        $sink = $limit === null ? @fopen($path, 'w+b') : CappedFileStream::open($path, $limit);

        if ($sink === false) {
            throw FileDoesNotExist::forPath('temporary file');
        }

        try {
            $response = $request->withOptions(['sink' => $sink])->get($url);
            $streamed = $this->streamedInto($response, $sink);
        } catch (Throwable $exception) {
            // Over the cap, the short write is what aborted the transfer.
            if ($limit !== null && $limit->exceeded) {
                throw RemoteFileRejected::tooLarge($url, $limit->maxBytes);
            }

            throw $exception;
        } finally {
            if (is_resource($sink)) {
                fclose($sink);
            }
        }

        if ($limit !== null && $limit->exceeded) {
            throw RemoteFileRejected::tooLarge($url, $limit->maxBytes);
        }

        if (! $streamed) {
            $this->writeBody($response, $url, $path, $maxBytes);
        }

        return $response;
    }

    /**
     * Whether the client streamed the body into `$sink`. It does not under `Http::fake()`, which
     * copies a stub's body over without rewinding it — so a stub answered twice arrives empty.
     *
     * @param  resource  $sink
     */
    private function streamedInto(Response $response, $sink): bool
    {
        $uri = stream_get_meta_data($sink)['uri'] ?? null;

        return $uri !== null && $response->toPsrResponse()->getBody()->getMetadata('uri') === $uri;
    }

    /** Write a response's own body to `$path` through the cap — for one not streamed there. */
    private function writeBody(Response $response, string $url, string $path, ?int $maxBytes): void
    {
        $body = $response->toPsrResponse()->getBody();
        $limit = $maxBytes === null ? null : new SizeLimit($maxBytes);
        $target = $limit === null ? @fopen($path, 'w+b') : CappedFileStream::open($path, $limit);

        if ($target === false) {
            throw FileDoesNotExist::forPath('temporary file');
        }

        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }

            while (! $body->eof() && ($chunk = $body->read(1048576)) !== '') {
                if (fwrite($target, $chunk) !== strlen($chunk)) {
                    break;
                }
            }
        } finally {
            fclose($target);
        }

        if ($limit !== null && $limit->exceeded) {
            throw RemoteFileRejected::tooLarge($url, $limit->maxBytes);
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
