<?php

declare(strict_types=1);

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\MediaLibrary\Actions\FetchRemoteUrlAction;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\FetchOptions;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\RemoteResponse;
use RoundlyConsulting\MediaLibrary\Enums\RemoteFetchReason;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFetchFailed;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFileRejected;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFileUnavailable;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Support\HostResolver;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\LocalHttpServer;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/*
 * `MediaLibrary::remote()->fetch()`: the guarded fetch behind `addFromUrl()`, as a public API that
 * returns the body. Every hop is canonicalised, vetted, pinned and re-vetted after a redirect;
 * bytes, time and redirects are capped; and the error tells a caller whether a retry can help.
 */

/** Make DNS answer `$answers` (host => addresses) for this test. */
function remoteDns(array $answers): void
{
    app()->instance(HostResolver::class, new HostResolver(
        static fn (string $host): array => $answers[$host] ?? [],
    ));
}

/** The exception a call throws, for asserting on its classification. */
function remoteFailure(Closure $call): RemoteFetchFailed
{
    try {
        $call();
    } catch (RemoteFetchFailed $exception) {
        return $exception;
    }

    throw new RuntimeException('Nothing was thrown.');
}

beforeEach(fn () => remoteDns(['example.com' => ['93.184.216.34'], 'cdn.example.net' => ['151.101.1.1']]));

it('returns the body, status, final url, content type and headers', function (): void {
    Http::fake(['example.com/*' => Http::response('<html>hi</html>', 200, ['Content-Type' => 'text/html; charset=utf-8', 'X-Thing' => ['a', 'b']])]);

    $page = MediaLibrary::remote()->fetch('https://example.com/page#section');

    expect($page)->toBeInstanceOf(RemoteResponse::class)
        ->and($page->body)->toBe('<html>hi</html>')
        ->and($page->status)->toBe(200)
        ->and($page->contentType)->toBe('text/html; charset=utf-8')
        ->and($page->url)->toBe('https://example.com/page')
        ->and($page->headers['X-Thing'])->toBe(['a', 'b']);

    Http::assertSent(static fn (Request $request): bool => $request->url() === 'https://example.com/page');
});

it('reports no content type when the server sends none', function (): void {
    Http::fake(['*' => Http::response('raw', 200)]);

    expect(MediaLibrary::remote()->fetch('https://example.com/raw')->contentType)->toBeNull();
});

it('follows a redirect to another public host and reports where it ended', function (): void {
    Http::fake([
        'example.com/*' => Http::response('', 301, ['Location' => 'https://cdn.example.net/files/doc.txt']),
        'cdn.example.net/*' => Http::response('document', 200),
    ]);

    $page = MediaLibrary::remote()->fetch('https://example.com/doc');

    expect($page->body)->toBe('document')
        ->and($page->url)->toBe('https://cdn.example.net/files/doc.txt');
    Http::assertSentCount(2);
});

it('refuses a redirect to a private address before requesting it', function (string $location): void {
    remoteDns(['example.com' => ['93.184.216.34'], 'inward.example' => ['10.0.0.5']]);
    Http::fake([
        'example.com/*' => Http::response('', 302, ['Location' => $location]),
        '*' => Http::response('{"AccessKeyId":"secret"}', 200),
    ]);

    $failure = remoteFailure(fn () => MediaLibrary::remote()->fetch('https://example.com/start'));

    expect($failure)->toBeInstanceOf(RemoteFileRejected::class)
        ->and($failure->reason())->toBe(RemoteFetchReason::Blocked)
        ->and($failure->getMessage())->not->toContain('10.0.0.5');
    Http::assertSentCount(1);
})->with([
    'metadata' => ['http://169.254.169.254/latest/meta-data/'],
    'protocol-relative' => ['//10.0.0.1/'],
    'ipv4-mapped' => ['http://[::ffff:a00:1]/'],
    'a name resolving inward' => ['http://inward.example/'],
    'localhost by name' => ['http://localhost:8080/'],
]);

it('caps the body exactly at maxBytes', function (): void {
    Http::fake([
        'example.com/ten' => Http::response(str_repeat('a', 10), 200),
        'example.com/eleven' => Http::response(str_repeat('a', 11), 200),
        'example.com/declared' => Http::response('tiny', 200, ['Content-Length' => '5000']),
    ]);

    expect(MediaLibrary::remote()->fetch('https://example.com/ten', maxBytes: 10)->body)->toBe(str_repeat('a', 10));

    foreach (['https://example.com/eleven', 'https://example.com/declared'] as $url) {
        $failure = remoteFailure(fn () => MediaLibrary::remote()->fetch($url, maxBytes: 10));

        expect($failure->reason())->toBe(RemoteFetchReason::TooLarge)
            ->and($failure->retryable())->toBeFalse();
    }
});

it('caps fetch() at media.remote.fetch_max_bytes, not media.max_file_size', function (): void {
    config()->set('media.remote.fetch_max_bytes', 4);
    config()->set('media.max_file_size', 1);
    Http::fake(['example.com/five' => Http::response('fives', 200), '*' => Http::response('four', 200)]);

    expect(MediaLibrary::remote()->fetch('https://example.com/four')->body)->toBe('four')
        ->and(fn () => MediaLibrary::remote()->fetch('https://example.com/five'))->toThrow(RemoteFileRejected::class, 'larger');
});

it('enforces the redirect budget', function (): void {
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://example.com/again'])]);

    $failure = remoteFailure(fn () => MediaLibrary::remote()->fetch('https://example.com/start', maxRedirects: 2));

    expect($failure->reason())->toBe(RemoteFetchReason::TooManyRedirects)
        ->and($failure->retryable())->toBeFalse();
    Http::assertSentCount(3);
});

it('never follows a redirect with media.remote.max_redirects at 0', function (): void {
    config()->set('media.remote.max_redirects', 0);
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://example.com/again'])]);

    expect(fn () => MediaLibrary::remote()->fetch('https://example.com/start'))->toThrow(RemoteFileRejected::class, 'redirect');
    Http::assertSentCount(1);
});

it('spends one timeout budget across every hop', function (): void {
    Carbon::setTestNow('2026-10-17 12:00:00');
    $timeouts = [];

    Http::fake(function (Request $request, array $options) use (&$timeouts) {
        $timeouts[] = $options['timeout'];
        Carbon::setTestNow(Carbon::now()->addSeconds(10));

        return Http::response('', 302, ['Location' => 'https://example.com/next']);
    });

    try {
        $failure = remoteFailure(fn () => MediaLibrary::remote()->fetch('https://example.com/start', timeout: 30));
    } finally {
        Carbon::setTestNow();
    }

    expect($timeouts)->toBe([30.0, 20.0, 10.0])
        ->and($failure)->toBeInstanceOf(RemoteFileUnavailable::class)
        ->and($failure->reason())->toBe(RemoteFetchReason::Timeout)
        ->and($failure->retryable())->toBeTrue();
});

it('treats a budget with less than a millisecond left as spent, never as no timeout', function (): void {
    // curl reads CURLOPT_TIMEOUT_MS as an integer, and 0 as "no timeout at all": a hop started
    // with 0.4 ms left would otherwise run unbounded.
    Carbon::setTestNow('2026-10-17 12:00:00.000000');

    Http::fake(function () {
        Carbon::setTestNow(Carbon::now()->addMicroseconds(29_999_600));

        return Http::response('', 302, ['Location' => 'https://example.com/next']);
    });

    try {
        $failure = remoteFailure(fn () => MediaLibrary::remote()->fetch('https://example.com/start', timeout: 30));
    } finally {
        Carbon::setTestNow();
    }

    expect($failure)->toBeInstanceOf(RemoteFileUnavailable::class)
        ->and($failure->reason())->toBe(RemoteFetchReason::Timeout);
    Http::assertSentCount(1);
});

it('keeps the status class of an error page larger than the cap', function (): void {
    Http::fake(['*' => Http::response(str_repeat('x', 2000), 503, ['Retry-After' => '5'])]);

    $failure = remoteFailure(fn () => MediaLibrary::remote()->fetch('https://example.com/x', maxBytes: 1000));

    expect($failure)->toBeInstanceOf(RemoteFileUnavailable::class)
        ->and($failure->reason())->toBe(RemoteFetchReason::Status)
        ->and($failure->status())->toBe(503)
        ->and($failure->retryAfter())->toBe(5);
});

it('follows a redirect whose body is larger than the cap', function (): void {
    Http::fake([
        'example.com/*' => Http::response(str_repeat('<p>moved</p>', 100), 301, ['Location' => 'https://cdn.example.net/doc']),
        'cdn.example.net/*' => Http::response('document', 200),
    ]);

    expect(MediaLibrary::remote()->fetch('https://example.com/old', maxBytes: 100)->body)->toBe('document');
});

it('follows only 301, 302, 303, 307 and 308', function (int $status): void {
    Http::fake([
        'example.com/*' => Http::response('', $status, ['Location' => 'https://cdn.example.net/doc']),
        '*' => Http::response('document', 200),
    ]);

    $failure = remoteFailure(fn () => MediaLibrary::remote()->fetch('https://example.com/x'));

    expect($failure->reason())->toBe(RemoteFetchReason::Status)
        ->and($failure->status())->toBe($status);
    Http::assertSentCount(1);
})->with([300, 304, 305, 306]);

it('refuses a redirect location with a backslash or a control character', function (string $location): void {
    Http::fake([
        'example.com/start' => Http::response('', 302, ['Location' => $location]),
        '*' => Http::response('document', 200),
    ]);

    $failure = remoteFailure(fn () => MediaLibrary::remote()->fetch('https://example.com/start'));

    expect($failure)->toBeInstanceOf(RemoteFileRejected::class)
        ->and($failure->reason())->toBe(RemoteFetchReason::InvalidUrl);
    Http::assertSentCount(1);
})->with([
    'backslash-led' => ['/\\evil.example/'],
    'double backslash' => ['\\\\evil.example/'],
    'backslash in a path' => ['a\\b'],
    'a tab' => ["/a\tb"],
]);

it('classifies an http status by whether a retry can heal it', function (int $status, bool $retryable): void {
    Http::fake(['*' => Http::response('nope', $status)]);

    $failure = remoteFailure(fn () => MediaLibrary::remote()->fetch('https://example.com/x'));

    expect($failure->retryable())->toBe($retryable)
        ->and($failure->status())->toBe($status)
        ->and($failure->reason())->toBe(RemoteFetchReason::Status)
        ->and($failure)->toBeInstanceOf($retryable ? RemoteFileUnavailable::class : RemoteFileRejected::class);
})->with([
    '400' => [400, false],
    '403' => [403, false],
    '404' => [404, false],
    '410' => [410, false],
    '304 without a request for it' => [304, false],
    '408' => [408, true],
    '429' => [429, true],
    '500' => [500, true],
    '503' => [503, true],
]);

it('carries Retry-After from a retryable status, seconds or date', function (): void {
    Carbon::setTestNow('2026-10-17 12:00:00');

    Http::fake([
        'example.com/seconds' => Http::response('', 429, ['Retry-After' => '120']),
        'example.com/date' => Http::response('', 503, ['Retry-After' => 'Sat, 17 Oct 2026 12:01:30 GMT']),
        'example.com/past' => Http::response('', 503, ['Retry-After' => 'Sat, 17 Oct 2026 11:00:00 GMT']),
        'example.com/junk' => Http::response('', 503, ['Retry-After' => 'soon']),
    ]);

    try {
        expect(remoteFailure(fn () => MediaLibrary::remote()->fetch('https://example.com/seconds'))->retryAfter())->toBe(120)
            ->and(remoteFailure(fn () => MediaLibrary::remote()->fetch('https://example.com/date'))->retryAfter())->toBe(90)
            ->and(remoteFailure(fn () => MediaLibrary::remote()->fetch('https://example.com/past'))->retryAfter())->toBe(0)
            ->and(remoteFailure(fn () => MediaLibrary::remote()->fetch('https://example.com/junk'))->retryAfter())->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

it('refuses a redirect status that names no location', function (): void {
    Http::fake(['*' => Http::response('', 302)]);

    expect(remoteFailure(fn () => MediaLibrary::remote()->fetch('https://example.com/x'))->status())->toBe(302);
});

it('classifies transport failures', function (Closure $failure, string $class, RemoteFetchReason $reason): void {
    Http::fake(['*' => $failure]);

    $exception = remoteFailure(fn () => MediaLibrary::remote()->fetch('https://example.com/x'));

    expect($exception)->toBeInstanceOf($class)
        ->and($exception->reason())->toBe($reason)
        ->and($exception->getMessage())->not->toContain('93.184.216.34');
})->with([
    'refused' => [HttpFactory::failedConnection('cURL error 7: Failed to connect to 93.184.216.34 port 443'), RemoteFileUnavailable::class, RemoteFetchReason::Connection],
    'no curl code' => [HttpFactory::failedConnection('boom'), RemoteFileUnavailable::class, RemoteFetchReason::Connection],
    'timed out' => [HttpFactory::failedConnection('cURL error 28: Operation timed out after 30001 milliseconds'), RemoteFileUnavailable::class, RemoteFetchReason::Timeout],
    'could not resolve' => [HttpFactory::failedConnection('cURL error 6: Could not resolve host: example.com'), RemoteFileUnavailable::class, RemoteFetchReason::Unresolved],
    // No response: Laravel reports a Guzzle RequestException as a ConnectionException.
    'tls verification' => [static fn (Request $request) => Create::rejectionFor(new RequestException('cURL error 60: SSL certificate problem: unable to get local issuer certificate', $request->toPsrRequest())), RemoteFileRejected::class, RemoteFetchReason::Tls],
    // Thrown past Laravel's handling, as a raw transfer error.
    'reset mid-body' => [static fn () => throw new RuntimeException('cURL error 56: Recv failure: Connection reset by peer'), RemoteFileUnavailable::class, RemoteFetchReason::Connection],
    'tls, raw' => [static fn () => throw new RuntimeException('cURL error 60: SSL certificate problem'), RemoteFileRejected::class, RemoteFetchReason::Tls],
]);

it('lets an error that is no transfer failure through untouched', function (): void {
    Http::fake(['*' => static fn () => throw new RuntimeException('something else')]);

    expect(fn () => MediaLibrary::remote()->fetch('https://example.com/x'))->toThrow(RuntimeException::class, 'something else');
});

it('reports a host that does not resolve as retryable', function (): void {
    remoteDns([]);
    Http::fake(['*' => Http::response('x', 200)]);

    $failure = remoteFailure(fn () => MediaLibrary::remote()->fetch('https://nowhere.example/x'));

    expect($failure)->toBeInstanceOf(RemoteFileUnavailable::class)
        ->and($failure->reason())->toBe(RemoteFetchReason::Unresolved)
        ->and($failure->retryable())->toBeTrue();
    Http::assertNothingSent();
});

it('pins every vetted address and refuses the environment proxy', function (): void {
    remoteDns(['dual.example.com' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']]);
    $sent = [];

    Http::fake(function (Request $request, array $options) use (&$sent) {
        $sent[] = $options;

        return Http::response('ok', 200);
    });

    MediaLibrary::remote()->fetch('https://dual.example.com/a');

    expect($sent[0]['curl'][CURLOPT_RESOLVE])->toBe(['dual.example.com:443:93.184.216.34,[2606:2800:220:1:248:1893:25c8:1946]'])
        ->and($sent[0]['proxy'])->toBe('');
});

it('sends configured headers to the first origin only', function (): void {
    config()->set('media.remote.headers', ['Authorization' => 'Bearer origin-token']);
    $sent = [];

    Http::fake(function (Request $request) use (&$sent) {
        $sent[$request->url()] = $request->header('Authorization');

        return match ($request->url()) {
            'https://example.com/a' => Http::response('', 302, ['Location' => '/b']),
            'https://example.com/b' => Http::response('', 302, ['Location' => 'https://cdn.example.net/c']),
            default => Http::response('done', 200),
        };
    });

    MediaLibrary::remote()->fetch('https://example.com/a');

    expect($sent)->toBe([
        'https://example.com/a' => ['Bearer origin-token'],
        'https://example.com/b' => ['Bearer origin-token'],
        'https://cdn.example.net/c' => [],
    ]);
});

it('treats another port or scheme as another origin', function (): void {
    config()->set('media.remote.headers', ['Authorization' => 'Bearer origin-token']);
    $sent = [];

    Http::fake(function (Request $request) use (&$sent) {
        $sent[$request->url()] = $request->header('Authorization');

        return match ($request->url()) {
            'https://example.com/a' => Http::response('', 302, ['Location' => 'https://example.com:8443/b']),
            'https://example.com:8443/b' => Http::response('', 302, ['Location' => 'http://example.com/c']),
            default => Http::response('done', 200),
        };
    });

    MediaLibrary::remote()->fetch('https://example.com/a');

    expect($sent)->toBe([
        'https://example.com/a' => ['Bearer origin-token'],
        'https://example.com:8443/b' => [],
        'http://example.com/c' => [],
    ]);
});

it('forwards configured headers across origins only when told to', function (): void {
    config()->set('media.remote.headers', ['X-Api-Key' => 'k']);
    config()->set('media.remote.forward_headers_on_redirect', true);
    $sent = [];

    Http::fake(function (Request $request) use (&$sent) {
        $sent[] = $request->header('X-Api-Key');

        return str_contains($request->url(), 'example.com')
            ? Http::response('', 302, ['Location' => 'https://cdn.example.net/c'])
            : Http::response('done', 200);
    });

    MediaLibrary::remote()->fetch('https://example.com/a');

    expect($sent)->toBe([['k'], ['k']]);
});

it('sends the accept header on every hop', function (): void {
    $sent = [];

    Http::fake(function (Request $request) use (&$sent) {
        $sent[] = $request->header('Accept');

        return str_ends_with($request->url(), '/a')
            ? Http::response('', 302, ['Location' => 'https://cdn.example.net/b'])
            : Http::response('done', 200);
    });

    MediaLibrary::remote()->fetch('https://example.com/a', accept: 'text/html, text/plain');

    expect($sent)->toBe([['text/html, text/plain'], ['text/html, text/plain']]);
});

it('fetches anything, unpinned, with the guard switched off', function (): void {
    config()->set('media.remote.block_private_networks', false);
    $sent = [];

    Http::fake(function (Request $request, array $options) use (&$sent) {
        $sent[] = $options;

        return str_ends_with($request->url(), '/start')
            ? Http::response('', 302, ['Location' => 'http://10.0.0.5/next'])
            : Http::response('internal', 200);
    });

    $page = MediaLibrary::remote()->fetch('http://127.0.0.1/start');

    expect($page->body)->toBe('internal')
        ->and($page->url)->toBe('http://10.0.0.5/next')
        ->and($sent[0])->not->toHaveKey('proxy')
        ->and($sent[0]['curl'] ?? [])->not->toHaveKey(CURLOPT_RESOLVE);
});

it('still refuses a non-http scheme with the guard switched off', function (): void {
    config()->set('media.remote.block_private_networks', false);

    expect(remoteFailure(fn () => MediaLibrary::remote()->fetch('file:///etc/passwd'))->reason())->toBe(RemoteFetchReason::Scheme);
});

it('refuses unusable fetch options', function (Closure $options, string $message): void {
    expect($options)->toThrow(InvalidArgumentException::class, $message);
})->with([
    'zero bytes' => [fn () => new FetchOptions(maxBytes: 0), 'maxBytes'],
    'zero timeout' => [fn () => new FetchOptions(timeout: 0), 'timeout'],
    'negative redirects' => [fn () => new FetchOptions(maxRedirects: -1), 'maxRedirects'],
    'blank accept' => [fn () => new FetchOptions(accept: ' '), 'accept'],
    'header injection' => [fn () => new FetchOptions(accept: "text/html\r\nX-Evil: 1"), 'accept'],
]);

it('runs the same fetch through the action', function (): void {
    Http::fake(['*' => Http::response('through the action', 200)]);

    $page = app(FetchRemoteUrlAction::class)->execute('https://example.com/a', new FetchOptions(maxBytes: 100));

    expect($page->body)->toBe('through the action');
});

it('reads the new remote settings strictly', function (string $key, mixed $value): void {
    config()->set($key, $value);
    Http::fake(['*' => Http::response('x', 200)]);

    expect(fn () => MediaLibrary::remote()->fetch('https://example.com/a'))->toThrow(InvalidConfigurationException::class, $key);
})->with([
    'a zero byte cap' => ['media.remote.fetch_max_bytes', 0],
    'a junk byte cap' => ['media.remote.fetch_max_bytes', 'lots'],
    'negative redirects' => ['media.remote.max_redirects', -1],
    'a junk switch' => ['media.remote.forward_headers_on_redirect', 'maybe'],
    'a port list that is not a list' => ['media.remote.allowed_ports', '443'],
    'an empty port list' => ['media.remote.allowed_ports', []],
    'port zero' => ['media.remote.allowed_ports', [443, 0]],
    'a port too high' => ['media.remote.allowed_ports', [70000]],
    'a junk port' => ['media.remote.allowed_ports', ['https']],
]);

it('fetches a real page through the pinned connection', function (): void {
    $server = LocalHttpServer::start();
    config()->set('media.remote.allowed_private_hosts', ['127.0.0.1']);
    remoteDns(['media-pin.invalid' => ['127.0.0.1']]);

    try {
        $page = MediaLibrary::remote()->fetch("http://media-pin.invalid:{$server->port}/redirect?to=%2Fhello.txt");

        expect($page->body)->toBe('hello from the pinned server')
            ->and($page->contentType)->toStartWith('text/plain')
            ->and($page->url)->toBe("http://media-pin.invalid:{$server->port}/hello.txt");
    } finally {
        $server->stop();
    }
})->skip(! extension_loaded('curl') || ! function_exists('proc_open'), 'needs ext-curl and proc_open');

it('counts decoded bytes, so a compressed bomb stops at the cap', function (): void {
    $server = LocalHttpServer::start();
    config()->set('media.remote.allowed_private_hosts', ['127.0.0.1']);

    try {
        $failure = remoteFailure(fn () => MediaLibrary::remote()->fetch("http://127.0.0.1:{$server->port}/gzip-bomb", maxBytes: 65536));

        expect($failure->reason())->toBe(RemoteFetchReason::TooLarge);
    } finally {
        $server->stop();
    }
})->skip(! extension_loaded('curl') || ! function_exists('proc_open'), 'needs ext-curl and proc_open');

it('stops a real endless body at the cap', function (): void {
    $server = LocalHttpServer::start();
    config()->set('media.remote.allowed_private_hosts', ['127.0.0.1']);
    $started = microtime(true);

    try {
        expect(remoteFailure(fn () => MediaLibrary::remote()->fetch("http://127.0.0.1:{$server->port}/endless", maxBytes: 65536, timeout: 5))->reason())
            ->toBe(RemoteFetchReason::TooLarge)
            ->and(microtime(true) - $started)->toBeLessThan(4.0);
    } finally {
        $server->stop();
    }
})->skip(! extension_loaded('curl') || ! function_exists('proc_open'), 'needs ext-curl and proc_open');

it('reports a real 503 as retryable with its Retry-After', function (): void {
    $server = LocalHttpServer::start();
    config()->set('media.remote.allowed_private_hosts', ['127.0.0.1']);

    try {
        $failure = remoteFailure(fn () => MediaLibrary::remote()->fetch("http://127.0.0.1:{$server->port}/unavailable"));

        expect($failure)->toBeInstanceOf(RemoteFileUnavailable::class)
            ->and($failure->status())->toBe(503)
            ->and($failure->retryAfter())->toBe(7);
    } finally {
        $server->stop();
    }
})->skip(! extension_loaded('curl') || ! function_exists('proc_open'), 'needs ext-curl and proc_open');

it('keeps a real 503 retryable however large its error page', function (): void {
    $server = LocalHttpServer::start();
    config()->set('media.remote.allowed_private_hosts', ['127.0.0.1']);
    $started = microtime(true);

    try {
        $failure = remoteFailure(fn () => MediaLibrary::remote()->fetch("http://127.0.0.1:{$server->port}/unavailable-endless", maxBytes: 1024, timeout: 5));

        expect($failure)->toBeInstanceOf(RemoteFileUnavailable::class)
            ->and($failure->status())->toBe(503)
            ->and($failure->retryAfter())->toBe(7)
            ->and(microtime(true) - $started)->toBeLessThan(4.0);
    } finally {
        $server->stop();
    }
})->skip(! extension_loaded('curl') || ! function_exists('proc_open'), 'needs ext-curl and proc_open');

it('follows a real redirect without reading its body', function (): void {
    $server = LocalHttpServer::start();
    config()->set('media.remote.allowed_private_hosts', ['127.0.0.1']);

    try {
        $page = MediaLibrary::remote()->fetch("http://127.0.0.1:{$server->port}/redirect-endless", maxBytes: 1024, timeout: 5);

        expect($page->body)->toBe('hello from the pinned server');
    } finally {
        $server->stop();
    }
})->skip(! extension_loaded('curl') || ! function_exists('proc_open'), 'needs ext-curl and proc_open');
