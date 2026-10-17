<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Actions\VetRemoteUrlAction;
use RoundlyConsulting\MediaLibrary\Enums\RemoteFetchReason;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFetchFailed;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFileRejected;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFileUnavailable;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Support\HostResolver;
use RoundlyConsulting\MediaLibrary\Support\RemoteUrlGuard;

/*
 * `vet()`: the guard's verdict on one URL, without fetching it. The contract fixture pins the
 * shared verdicts; these pin the PHP-side details around them — the VettedUrl it returns, the
 * allowlist, the port policy and internationalised host names.
 */

function guardDns(array $answers): void
{
    app()->instance(HostResolver::class, new HostResolver(
        static fn (string $host): array => $answers[$host] ?? throw new RuntimeException("Unexpected lookup of {$host}"),
    ));
}

function vetReason(string $url): string
{
    try {
        MediaLibrary::remote()->vet($url);

        return 'ok';
    } catch (RemoteFetchFailed $exception) {
        return $exception->reason()->value;
    }
}

it('returns the canonical url, host, port and every vetted address', function (): void {
    guardDns(['files.example.com' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']]);

    $vetted = MediaLibrary::remote()->vet('HTTPS://Files.Example.com./a/b?x=1#frag');

    expect($vetted->url)->toBe('https://files.example.com/a/b?x=1')
        ->and($vetted->host)->toBe('files.example.com')
        ->and($vetted->port)->toBe(443)
        ->and($vetted->addresses)->toBe(['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'])
        ->and($vetted->resolveEntry())->toBe('files.example.com:443:93.184.216.34,[2606:2800:220:1:248:1893:25c8:1946]');
});

it('keeps an explicit port and drops a default one', function (): void {
    guardDns(['example.com' => ['93.184.216.34']]);

    expect(MediaLibrary::remote()->vet('http://example.com:8080')->url)->toBe('http://example.com:8080/')
        ->and(MediaLibrary::remote()->vet('http://example.com:8080')->port)->toBe(8080)
        ->and(MediaLibrary::remote()->vet('https://example.com:443/x')->url)->toBe('https://example.com/x')
        ->and(MediaLibrary::remote()->vet('http://example.com:/x')->port)->toBe(80)
        ->and(MediaLibrary::remote()->vet('http://example.com?q=1')->url)->toBe('http://example.com/?q=1');
});

it('needs no pin for an address literal', function (): void {
    guardDns([]);

    $v4 = MediaLibrary::remote()->vet('http://8.8.8.8/');
    $v6 = MediaLibrary::remote()->vet('http://[2606:4700:4700:0:0:0:0:1111]:8080/');

    expect($v4->addresses)->toBe(['8.8.8.8'])
        ->and($v4->resolveEntry())->toBeNull()
        ->and($v6->host)->toBe('2606:4700:4700::1111')
        ->and($v6->url)->toBe('http://[2606:4700:4700::1111]:8080/')
        ->and($v6->resolveEntry())->toBeNull();
});

it('trusts an allowlisted host name without resolving it, localhost included', function (): void {
    guardDns([]);
    config()->set('media.remote.allowed_private_hosts', ['minio.internal', 'localhost']);

    $vetted = MediaLibrary::remote()->vet('http://MINIO.internal:9000/bucket');

    expect($vetted->addresses)->toBe([])
        ->and($vetted->resolveEntry())->toBeNull()
        ->and(vetReason('http://localhost/'))->toBe('ok')
        ->and(vetReason('http://api.localhost/'))->toBe('blocked');
});

it('lets an allowlisted range through, and nothing else private', function (): void {
    guardDns(['files.internal' => ['10.20.30.40'], 'other.internal' => ['192.168.1.1']]);
    config()->set('media.remote.allowed_private_hosts', ['10.0.0.0/8']);

    expect(vetReason('http://files.internal/'))->toBe('ok')
        ->and(vetReason('http://10.1.1.1/'))->toBe('ok')
        ->and(vetReason('http://other.internal/'))->toBe('blocked');
});

it('judges even with the fetch guard switched off', function (): void {
    guardDns([]);
    config()->set('media.remote.block_private_networks', false);

    expect(vetReason('http://127.0.0.1/'))->toBe('blocked');
});

it('applies media.remote.allowed_ports, default ports included', function (): void {
    guardDns(['example.com' => ['93.184.216.34']]);
    config()->set('media.remote.allowed_ports', [443, '8443']);

    expect(vetReason('https://example.com/'))->toBe('ok')
        ->and(vetReason('https://example.com:8443/'))->toBe('ok')
        ->and(vetReason('http://example.com/'))->toBe('port')
        ->and(vetReason('https://example.com:444/'))->toBe('port');
});

it('reads unresolvable as retryable and the rest as terminal', function (): void {
    guardDns(['inward.example' => ['10.0.0.5'], 'nowhere.example' => []]);

    expect(fn () => MediaLibrary::remote()->vet('http://inward.example/'))->toThrow(RemoteFileRejected::class)
        ->and(fn () => app(VetRemoteUrlAction::class)->execute('http://nowhere.example/'))->toThrow(RemoteFileUnavailable::class);
});

it('converts an internationalised host name with ext-intl', function (): void {
    guardDns(['xn--bcher-kva.example' => ['93.184.216.34']]);

    expect(MediaLibrary::remote()->vet('https://Bücher.example/')->host)->toBe('xn--bcher-kva.example')
        ->and(vetReason('http://ⓛocalhost/'))->toBe('blocked')
        ->and(vetReason('http://127。0。0。1/'))->toBe('blocked')
        ->and(vetReason("http://a\u{200D}b.example/"))->toBe('non_canonical_host');
})->skip(! extension_loaded('intl'), 'needs ext-intl');

it('refuses an internationalised host name without ext-intl, saying why', function (): void {
    app()->instance(RemoteUrlGuard::class, new RemoteUrlGuard(app(HostResolver::class), intl: false));

    try {
        MediaLibrary::remote()->vet('https://bücher.example/');
        $this->fail('The host was accepted.');
    } catch (RemoteFileRejected $exception) {
        expect($exception->reason())->toBe(RemoteFetchReason::NonCanonicalHost)
            ->and($exception->getMessage())->toContain('ext-intl');
    }
});

it('refuses what the shared vectors do not spell out', function (string $url, string $reason): void {
    guardDns(['example.com' => ['93.184.216.34']]);

    expect(vetReason($url))->toBe($reason);
})->with([
    'a tab in the path' => ["http://example.com/a\tb", 'invalid_url'],
    'a newline in the query' => ["http://example.com/?a=\n", 'invalid_url'],
    'a space in the path is fine' => ['http://example.com/a b', 'ok'],
    'an unclosed bracket' => ['http://[::1/', 'non_canonical_host'],
    'a bracketed ipv4' => ['http://[1.2.3.4]/', 'non_canonical_host'],
    'junk after the bracket' => ['http://[2606:4700::1]x/', 'non_canonical_host'],
    'an unbracketed ipv6' => ['http://2606:4700::1/', 'non_canonical_host'],
    'a bracket in a name' => ['http://exa]mple.com/', 'non_canonical_host'],
    'a dot alone' => ['http://./', 'non_canonical_host'],
    'a label too long' => ['http://'.str_repeat('a', 64).'.com/', 'non_canonical_host'],
    'a name too long' => ['http://'.implode('.', array_fill(0, 51, 'abcd')).'.com/', 'non_canonical_host'],
    'an illegal character' => ['http://exa!mple.com/', 'non_canonical_host'],
    'a hex last label' => ['http://example.0x1/', 'non_canonical_host'],
    'an empty host with a port' => ['http://:80/', 'invalid_url'],
    'leading zeros in a port' => ['http://example.com:000080/', 'ok'],
    'a port past any integer' => ['http://example.com:99999999999999999999999/', 'port'],
    'a negative port' => ['http://example.com:-1/', 'invalid_url'],
    'a control character in the host' => ["http://exam\x01ple.com/", 'non_canonical_host'],
]);
