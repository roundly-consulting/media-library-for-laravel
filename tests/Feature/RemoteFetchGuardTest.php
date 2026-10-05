<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFileRejected;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Support\HostResolver;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\LocalHttpServer;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/*
 * `addFromUrl()` takes URLs from users (review photo links). Without a guard it fetches whatever
 * the URL names — the cloud metadata service's credentials, an admin panel on localhost — and
 * stores the answer as public media. A host allowlist does not help: a public host can redirect
 * to 169.254.169.254, and a name can resolve to a private address.
 */

/** Make DNS answer `$answers` (host => addresses) for this test. */
function resolving(array $answers): void
{
    app()->instance(HostResolver::class, new HostResolver(
        static fn (string $host): array => $answers[$host] ?? [],
    ));
}

it('refuses a url pointing at a private, loopback, link-local or metadata address', function (string $url): void {
    Http::fake(['*' => Http::response('secret', 200)]);

    expect(fn () => MediaLibrary::addFromUrl($url))->toThrow(RemoteFileRejected::class, 'private');

    Http::assertNothingSent();
})->with([
    'loopback' => 'http://127.0.0.1/admin',
    'cloud metadata' => 'http://169.254.169.254/latest/meta-data/iam/security-credentials/',
    'private 10/8' => 'http://10.0.0.5/x.png',
    'private 192.168/16' => 'https://192.168.1.1/x.png',
    'carrier-grade nat' => 'http://100.100.100.200/latest/meta-data',
    'unspecified' => 'http://0.0.0.0/',
    'ipv6 loopback' => 'http://[::1]/x.png',
    'ipv4-mapped ipv6 loopback' => 'http://[::ffff:127.0.0.1]/x.png',
    'ipv4-mapped ipv6 metadata' => 'http://[::ffff:a9fe:a9fe]/latest/meta-data',
    'ipv6 unique local (aws imds)' => 'http://[fd00:ec2::254]/latest/meta-data',
    'ipv6 link-local' => 'http://[fe80::1]/x.png',
]);

it('refuses a public url that redirects to the metadata service, without following it', function (): void {
    resolving(['public.example.com' => ['93.184.216.34']]);

    Http::fake([
        'public.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data/']),
        '169.254.169.254/*' => Http::response('{"AccessKeyId":"secret"}', 200),
    ]);

    expect(fn () => MediaLibrary::addFromUrl('https://public.example.com/avatar.png'))
        ->toThrow(RemoteFileRejected::class, 'private');

    Http::assertSentCount(1);
    Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '169.254.169.254'));
});

it('refuses a host name that resolves to a private address', function (): void {
    resolving(['internal.example.com' => ['10.0.0.7']]);
    Http::fake(['*' => Http::response('secret', 200)]);

    expect(fn () => MediaLibrary::addFromUrl('https://internal.example.com/x.png'))
        ->toThrow(RemoteFileRejected::class, 'private');

    Http::assertNothingSent();
});

it('refuses a host name that also resolves to a private address', function (): void {
    resolving(['mixed.example.com' => ['93.184.216.34', '127.0.0.1']]);
    Http::fake(['*' => Http::response('secret', 200)]);

    expect(fn () => MediaLibrary::addFromUrl('https://mixed.example.com/x.png'))
        ->toThrow(RemoteFileRejected::class, 'private');
});

it('refuses a host name that does not resolve', function (): void {
    resolving([]);
    Http::fake(['*' => Http::response('secret', 200)]);

    expect(fn () => MediaLibrary::addFromUrl('https://nowhere.example.com/x.png'))
        ->toThrow(RemoteFileRejected::class, 'resolved');

    Http::assertNothingSent();
});

it('pins the connection to the address it vetted, so the name cannot be re-resolved', function (): void {
    resolving(['public.example.com' => ['93.184.216.34']]);

    $pins = [];

    Http::fake(function (Request $request, array $options) use (&$pins) {
        $pins[] = $options['curl'][CURLOPT_RESOLVE] ?? null;

        return Http::response('bytes', 200);
    });

    MediaLibrary::addFromUrl('https://public.example.com/a.txt')->toBucket('library');

    expect($pins)->toBe([['public.example.com:443:93.184.216.34']]);
});

it('pins an ipv6 address in brackets, on the url port', function (): void {
    resolving(['v6.example.com' => ['2606:2800:220:1:248:1893:25c8:1946']]);

    $pins = [];

    Http::fake(function (Request $request, array $options) use (&$pins) {
        $pins[] = $options['curl'][CURLOPT_RESOLVE] ?? null;

        return Http::response('bytes', 200);
    });

    MediaLibrary::addFromUrl('http://v6.example.com:8080/a.txt')->toBucket('library');

    expect($pins)->toBe([['v6.example.com:8080:[2606:2800:220:1:248:1893:25c8:1946]']]);
});

it('follows a redirect to another public host, vetting every hop', function (): void {
    resolving(['a.example.com' => ['93.184.216.34'], 'cdn.example.net' => ['151.101.1.1']]);

    Http::fake([
        'a.example.com/*' => Http::response('', 301, ['Location' => 'https://cdn.example.net/files/photo.txt']),
        'cdn.example.net/*' => Http::response('photo bytes', 200),
    ]);

    $media = MediaLibrary::addFromUrl('https://a.example.com/photo.txt')->toBucket('library');

    expect($media->size)->toBe(strlen('photo bytes'));
    Http::assertSentCount(2);
});

it('resolves a relative redirect against the url it came from', function (string $location, string $expected): void {
    resolving(['a.example.com' => ['93.184.216.34']]);

    Http::fake(function (Request $request) {
        return str_ends_with($request->url(), '/start')
            ? Http::response('', 302, ['Location' => test()->location])
            : Http::response('moved bytes', 200);
    });
    test()->location = $location;

    MediaLibrary::addFromUrl('https://a.example.com/dir/start')->toBucket('library');

    Http::assertSent(static fn (Request $request): bool => $request->url() === $expected);
})->with([
    'absolute path' => ['/other/file.txt', 'https://a.example.com/other/file.txt'],
    'relative path' => ['file.txt?v=2', 'https://a.example.com/dir/file.txt?v=2'],
    'dot segments' => ['../up.txt', 'https://a.example.com/up.txt'],
    'scheme relative' => ['//a.example.com/x.txt', 'https://a.example.com/x.txt'],
    'query only' => ['?page=2', 'https://a.example.com/dir/start?page=2'],
]);

it('gives up after five redirects', function (): void {
    resolving(['loop.example.com' => ['93.184.216.34']]);
    Http::fake(['*' => Http::response('', 302, ['Location' => 'https://loop.example.com/again'])]);

    expect(fn () => MediaLibrary::addFromUrl('https://loop.example.com/start'))
        ->toThrow(RemoteFileRejected::class, 'redirect');

    Http::assertSentCount(6);
});

it('refuses a redirect to a non-http scheme', function (): void {
    resolving(['a.example.com' => ['93.184.216.34']]);
    Http::fake(['*' => Http::response('', 302, ['Location' => 'file:///etc/passwd'])]);

    expect(fn () => MediaLibrary::addFromUrl('https://a.example.com/start'))
        ->toThrow(RemoteFileRejected::class, 'http');
});

it('fetches an allowlisted private host name', function (): void {
    config()->set('media.remote.allowed_private_hosts', ['minio.internal']);
    resolving(['minio.internal' => ['10.0.0.9']]);
    Http::fake(['*' => Http::response('internal bytes', 200)]);

    $media = MediaLibrary::addFromUrl('http://MINIO.internal/bucket/a.txt')->toBucket('library');

    expect($media->size)->toBe(strlen('internal bytes'));
});

it('fetches an address inside an allowlisted range', function (): void {
    config()->set('media.remote.allowed_private_hosts', ['10.0.0.0/8', 'fd00::/8']);
    resolving(['files.internal' => ['10.20.30.40']]);
    Http::fake(['*' => Http::response('internal bytes', 200)]);

    MediaLibrary::addFromUrl('http://files.internal/a.txt')->toBucket('library');
    MediaLibrary::addFromUrl('http://[fd00::5]/a.txt')->toBucket('library');
    MediaLibrary::addFromUrl('http://[::ffff:10.1.1.1]/a.txt')->toBucket('library');

    Http::assertSentCount(3);

    expect(fn () => MediaLibrary::addFromUrl('http://192.168.0.1/a.txt'))->toThrow(RemoteFileRejected::class, 'private');
});

it('fetches any address when the guard is switched off', function (): void {
    config()->set('media.remote.block_private_networks', false);
    Http::fake(['*' => Http::response('local bytes', 200)]);

    $media = MediaLibrary::addFromUrl('http://127.0.0.1/a.txt')->toBucket('library');

    expect($media->size)->toBe(strlen('local bytes'));
});

it('reads the remote guard settings strictly', function (string $key, mixed $value): void {
    config()->set($key, $value);
    Http::fake(['*' => Http::response('bytes', 200)]);

    expect(fn () => MediaLibrary::addFromUrl('https://example.com/a.txt'))->toThrow(InvalidConfigurationException::class);
})->with([
    'a non-boolean switch' => ['media.remote.block_private_networks', 'sometimes'],
    'a non-list allowlist' => ['media.remote.allowed_private_hosts', 'minio.internal'],
    'a blank entry' => ['media.remote.allowed_private_hosts', ['']],
    'a broken range' => ['media.remote.allowed_private_hosts', ['10.0.0.0/33']],
    'a range on no address' => ['media.remote.allowed_private_hosts', ['intranet/8']],
]);

it('pins the real connection to the vetted address', function (): void {
    $server = LocalHttpServer::start();
    config()->set('media.remote.allowed_private_hosts', ['127.0.0.1']);

    // No DNS has heard of this name: the request only lands because curl is pinned to the address.
    resolving(['media-pin.invalid' => ['127.0.0.1']]);

    try {
        $media = MediaLibrary::addFromUrl("http://media-pin.invalid:{$server->port}/hello.txt")->toBucket('library');

        expect(Storage::disk('public')->get($media->getPath()))->toBe('hello from the pinned server');
    } finally {
        $server->stop();
    }
})->skip(! extension_loaded('curl') || ! function_exists('proc_open'), 'needs ext-curl and proc_open');

it('stops a real redirect to an address the guard does not allow', function (): void {
    $server = LocalHttpServer::start();
    config()->set('media.remote.allowed_private_hosts', ['127.0.0.1']);
    resolving(['media-pin.invalid' => ['127.0.0.1']]);

    $target = urlencode("http://127.0.0.2:{$server->port}/hello.txt");

    try {
        expect(fn () => MediaLibrary::addFromUrl("http://media-pin.invalid:{$server->port}/redirect?to={$target}"))
            ->toThrow(RemoteFileRejected::class, 'private');
    } finally {
        $server->stop();
    }
})->skip(! extension_loaded('curl') || ! function_exists('proc_open'), 'needs ext-curl and proc_open');
