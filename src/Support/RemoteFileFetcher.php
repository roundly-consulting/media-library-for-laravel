<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\FetchOptions;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\RemoteResponse;
use RoundlyConsulting\MediaLibrary\Exceptions\FileDoesNotExist;
use RoundlyConsulting\MediaLibrary\Exceptions\MediaLibraryException;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFetchFailed;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFileRejected;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFileUnavailable;
use RuntimeException;
use Throwable;

/**
 * Fetches a user-supplied URL through Laravel's HTTP client — so `Http::fake()` still stands in for
 * it — without letting it reach the host's own network: one hop loop behind `addFromUrl()`
 * ({@see self::download()}, streamed to a local file) and `MediaLibrary::remote()->fetch()`
 * ({@see self::fetch()}, read back into memory). Both are cut off at a byte cap however the
 * server sends the body (counted after decoding, so a compressed bomb stops too).
 *
 * With `media.remote.block_private_networks` on (the default), every hop is judged by the
 * {@see RemoteUrlGuard} before it is requested: the URL is canonicalised and rebuilt, its host
 * resolved once, every address must be public unless allowlisted, the connection is pinned to
 * every vetted address (so a second DNS answer cannot swap in another, and a dead first address
 * fails over only to vetted ones), and the environment's proxy is bypassed (a proxy would resolve
 * the name itself). Redirects are followed by hand, up to `media.remote.max_redirects`, each one
 * judged the same way. `media.remote.headers` go to the first origin only, unless
 * `media.remote.forward_headers_on_redirect`, and one `timeout` budget covers every hop.
 *
 * @internal
 */
final class RemoteFileFetcher
{
    /** cURL errors that mean the peer's TLS certificate could not be verified: retrying cannot help. */
    private const array TLS_ERRORS = [51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];

    public function __construct(
        private readonly RemoteUrlGuard $guard,
    ) {}

    /**
     * Download `$url` into the local file at `$path` for `addFromUrl()`, streamed straight to
     * disk. With `$maxBytes` the transfer is cut off as soon as the body passes it, whatever the
     * server declared. Returns the last response whatever its status: `addFromUrl()` reports a
     * non-2xx answer as it always has, and a transport failure surfaces as Laravel's own exception.
     *
     * @throws RemoteFileRejected when a hop is refused (a host that does not resolve included), when
     *                            it redirects too often, or when the body passes `$maxBytes`
     * @throws ConnectionException when a hop fails, or when the time budget runs out
     */
    public function download(string $url, string $path, ?int $maxBytes): Response
    {
        [$response, $sink] = $this->follow(
            $url, $path, $maxBytes, MediaConfig::maxRedirects(), MediaConfig::remoteTimeout(), null, strict: false,
        );

        fclose($sink);

        return $response;
    }

    /**
     * Fetch `$url` into memory for `MediaLibrary::remote()->fetch()`: a 2xx answer, or an exception
     * that says whether retrying can help.
     *
     * @throws RemoteFetchFailed
     */
    public function fetch(string $url, FetchOptions $options): RemoteResponse
    {
        $maxBytes = $options->maxBytes ?? MediaConfig::fetchMaxBytes();

        [$response, $sink, $final] = $this->follow(
            $url,
            'php://temp',
            $maxBytes,
            $options->maxRedirects ?? MediaConfig::maxRedirects(),
            $options->timeout ?? MediaConfig::remoteTimeout(),
            $options->accept,
            strict: true,
        );

        try {
            if (! $response->successful()) {
                throw self::failedStatus($final, $response);
            }

            $declared = $response->header('Content-Length');

            // A body cut short of what the server declared is no whole answer either.
            if (is_numeric($declared) && (int) $declared > $maxBytes) {
                throw RemoteFileRejected::tooLarge($final, $maxBytes);
            }

            rewind($sink);
            $body = (string) stream_get_contents($sink);
        } finally {
            fclose($sink);
        }

        $contentType = $response->header('Content-Type');

        return new RemoteResponse($body, $response->status(), $contentType === '' ? null : $contentType, $final, self::headers($response));
    }

    /**
     * The hop loop: vet, pin, request, and follow a redirect by hand — until an answer that is not
     * a redirect, which is returned with the open sink holding its body and the URL it came from.
     *
     * `$strict` is the fetch API: an unresolvable host and a transport failure become
     * status-aware exceptions. Off, `addFromUrl()` keeps reporting them the way it always has.
     *
     * @return array{0: Response, 1: resource, 2: string}
     */
    private function follow(string $url, string $target, ?int $maxBytes, int $maxRedirects, int $timeout, ?string $accept, bool $strict): array
    {
        $guarded = MediaConfig::blockPrivateNetworks();
        $headers = MediaConfig::remoteHeaders();
        $forward = MediaConfig::forwardHeadersOnRedirect();
        $deadline = self::now() + $timeout;
        $origin = null;

        for ($redirects = 0; ; $redirects++) {
            [$requestUrl, $hopOrigin, $pin] = $guarded ? $this->vetHop($url, $strict) : self::unguardedHop($url);
            $origin ??= $hopOrigin;
            $remaining = $deadline - self::now();

            if ($remaining <= 0) {
                throw $strict
                    ? RemoteFileUnavailable::timedOut($requestUrl, $timeout)
                    : new ConnectionException("The remote file [{$requestUrl}] did not answer within {$timeout} seconds.");
            }

            $request = Http::withoutRedirecting()->withOptions(['timeout' => $remaining]);

            if ($headers !== [] && ($forward || $hopOrigin === $origin)) {
                $request->withHeaders($headers);
            }

            if ($accept !== null) {
                $request->accept($accept);
            }

            if ($guarded) {
                $request->withOptions(['proxy' => '']);

                if ($pin !== null) {
                    $request->withOptions(['curl' => [CURLOPT_RESOLVE => [$pin]]]);
                }
            }

            [$response, $sink] = $strict
                ? $this->sendClassified($request, $requestUrl, $target, $maxBytes, $timeout)
                : $this->send($request, $requestUrl, $target, $maxBytes);

            $location = $response->redirect() ? trim($response->header('Location')) : '';

            if ($location === '') {
                return [$response, $sink, $requestUrl];
            }

            fclose($sink);

            if ($redirects >= $maxRedirects) {
                throw RemoteFileRejected::tooManyRedirects($requestUrl, $maxRedirects);
            }

            $url = self::resolveReference($requestUrl, $location);
        }
    }

    /**
     * Judge one hop: the URL to request (rebuilt from the vetted parts), its origin and the pin.
     *
     * @return array{0: string, 1: string, 2: ?string}
     */
    private function vetHop(string $url, bool $strict): array
    {
        try {
            $vetted = $this->guard->vet($url);
        } catch (RemoteFileUnavailable $exception) {
            // addFromUrl() has always refused a host it cannot resolve.
            throw $strict ? $exception : RemoteFileRejected::unresolvableHost($url);
        }

        $pin = $vetted->resolveEntry();

        // Without curl, Laravel's client cannot be told which addresses to use.
        if ($pin !== null && ! extension_loaded('curl')) {
            throw RemoteFileRejected::cannotPin($url);
        }

        $scheme = substr($vetted->url, 0, (int) strpos($vetted->url, ':'));
        $host = str_contains($vetted->host, ':') ? '['.$vetted->host.']' : $vetted->host;

        return [$vetted->url, "{$scheme}://{$host}:{$vetted->port}", $pin];
    }

    /**
     * A hop with the guard switched off: only the scheme is checked, nothing is pinned.
     *
     * @return array{0: string, 1: string, 2: null}
     */
    private static function unguardedHop(string $url): array
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw RemoteFileRejected::unsupportedScheme($url);
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $port = parse_url($url, PHP_URL_PORT) ?? ($scheme === 'https' ? 443 : 80);

        return [$url, "{$scheme}://{$host}:{$port}", null];
    }

    /**
     * One request of the fetch API, its transport failures classified.
     *
     * @return array{0: Response, 1: resource}
     */
    private function sendClassified(PendingRequest $request, string $url, string $target, ?int $maxBytes, int $timeout): array
    {
        try {
            return $this->send($request, $url, $target, $maxBytes);
        } catch (MediaLibraryException $exception) {
            throw $exception;
        } catch (ConnectionException $exception) {
            throw self::transportFailure($url, $exception, $timeout);
        } catch (RuntimeException $exception) {
            // Guzzle's own transfer errors (a TLS failure, a reset mid-body) carry a cURL code.
            if (preg_match('/^cURL error \d+/', $exception->getMessage()) !== 1) {
                throw $exception;
            }

            throw self::transportFailure($url, $exception, $timeout);
        }
    }

    /**
     * A transport failure as a status-aware exception, by its cURL code. The underlying message is
     * not kept: it can name the address the connection went to.
     */
    private static function transportFailure(string $url, Throwable $exception, int $timeout): RemoteFetchFailed
    {
        $code = preg_match('/cURL error (\d+)/', $exception->getMessage(), $match) === 1 ? (int) $match[1] : null;

        return match (true) {
            $code === 28 => RemoteFileUnavailable::timedOut($url, $timeout),
            $code === 6 => RemoteFileUnavailable::unresolvable($url),
            $code !== null && in_array($code, self::TLS_ERRORS, true) => RemoteFileRejected::tlsFailed($url, $code),
            default => RemoteFileUnavailable::connectionFailed($url, $code),
        };
    }

    /** A non-2xx answer: retryable for 408, 429 and 5xx, terminal otherwise. */
    private static function failedStatus(string $url, Response $response): RemoteFetchFailed
    {
        $status = $response->status();

        if ($status === 408 || $status === 429 || $status >= 500) {
            return RemoteFileUnavailable::failedStatus($url, $status, self::retryAfter($response->header('Retry-After')));
        }

        return RemoteFileRejected::failedStatus($url, $status);
    }

    /** `Retry-After` in seconds — given as seconds or as an HTTP date — or null when absent or unreadable. */
    private static function retryAfter(string $value): ?int
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        try {
            $at = CarbonImmutable::createFromFormat(DATE_RFC7231, $value, 'UTC');
        } catch (InvalidFormatException) {
            return null;
        }

        return $at === null ? null : max(0, $at->getTimestamp() - CarbonImmutable::now()->getTimestamp());
    }

    /**
     * One request, its body written to `$target` (truncated first) through the size cap. Returns
     * the response and the sink, still open; a sink that does not exist is the caller's to close.
     *
     * @return array{0: Response, 1: resource}
     */
    private function send(PendingRequest $request, string $url, string $target, ?int $maxBytes): array
    {
        $limit = $maxBytes === null ? null : new SizeLimit($maxBytes);
        $sink = self::open($target, $limit);

        try {
            $response = $request->withOptions(['sink' => $sink])->get($url);
        } catch (Throwable $exception) {
            fclose($sink);

            // Over the cap, the short write is what aborted the transfer.
            if ($limit !== null && $limit->exceeded) {
                throw RemoteFileRejected::tooLarge($url, $limit->maxBytes);
            }

            throw $exception;
        }

        if ($limit !== null && $limit->exceeded) {
            fclose($sink);

            throw RemoteFileRejected::tooLarge($url, $limit->maxBytes);
        }

        if (! $this->streamedInto($response, $sink)) {
            fclose($sink);
            $sink = $this->copyBody($response, $url, $target, $maxBytes);
        }

        return [$response, $sink];
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

    /**
     * A response's own body, written through the cap into a fresh sink at `$target` — for one not
     * streamed there.
     *
     * @return resource
     */
    private function copyBody(Response $response, string $url, string $target, ?int $maxBytes)
    {
        $body = $response->toPsrResponse()->getBody();
        $limit = $maxBytes === null ? null : new SizeLimit($maxBytes);
        $sink = self::open($target, $limit);

        try {
            if ($body->isSeekable()) {
                $body->rewind();
            }

            while (! $body->eof() && ($chunk = $body->read(1048576)) !== '') {
                if (fwrite($sink, $chunk) !== strlen($chunk)) {
                    break;
                }
            }
        } catch (Throwable $exception) {
            fclose($sink);

            throw $exception;
        }

        if ($limit !== null && $limit->exceeded) {
            fclose($sink);

            throw RemoteFileRejected::tooLarge($url, $limit->maxBytes);
        }

        return $sink;
    }

    /**
     * Open a sink at `$target` (a local file, or `php://temp`), through the cap when there is one.
     *
     * @return resource
     */
    private static function open(string $target, ?SizeLimit $limit)
    {
        $sink = $limit === null ? @fopen($target, 'w+b') : CappedFileStream::open($target, $limit);

        if ($sink === false) {
            throw FileDoesNotExist::forPath('temporary file');
        }

        return $sink;
    }

    /**
     * A response's headers by name, each a list of values.
     *
     * @return array<string, list<string>>
     */
    private static function headers(Response $response): array
    {
        $headers = [];

        foreach ($response->headers() as $name => $values) {
            $headers[(string) $name] = array_values(array_map(strval(...), (array) $values));
        }

        return $headers;
    }

    /** Seconds since the epoch, to the microsecond; follows Carbon's test clock. */
    private static function now(): float
    {
        return CarbonImmutable::now()->getPreciseTimestamp(6) / 1_000_000;
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
