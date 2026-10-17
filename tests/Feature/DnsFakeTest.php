<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\ExpectationFailedException;
use RoundlyConsulting\MediaLibrary\Enums\RemoteFetchReason;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFetchFailed;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFileRejected;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFileUnavailable;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Rules\SafeRemoteUrl;
use RoundlyConsulting\MediaLibrary\Testing\DnsFake;

/*
 * `MediaLibrary::fakeDns()`: the public way for a host's tests to answer the remote URL guard's DNS
 * lookups. Everything here goes through the public API only — no internal resolver is bound.
 */

/** The RemoteFetchFailed a call throws. */
function dnsFakeFailure(Closure $call): RemoteFetchFailed
{
    try {
        $call();
    } catch (RemoteFetchFailed $exception) {
        return $exception;
    }

    throw new RuntimeException('Nothing was thrown.');
}

it('resolves a pinned host to a public address, so fetch() succeeds under Http::fake()', function (): void {
    MediaLibrary::fakeDns()->resolve('docs.example', '93.184.216.34');
    Http::fake(['docs.example/*' => Http::response('hello', 200, ['Content-Type' => 'text/plain'])]);

    $page = MediaLibrary::remote()->fetch('https://docs.example/readme.txt');

    expect($page->body)->toBe('hello')
        ->and($page->url)->toBe('https://docs.example/readme.txt')
        ->and(MediaLibrary::remote()->vet('https://docs.example/')->addresses)->toBe(['93.184.216.34']);
    Http::assertSentCount(1);
});

it('refuses a host pinned to a private address before any request', function (string $address): void {
    MediaLibrary::fakeDns()->resolve('intranet.example', $address);
    Http::fake();

    $failure = dnsFakeFailure(fn () => MediaLibrary::remote()->fetch('https://intranet.example/'));

    expect($failure)->toBeInstanceOf(RemoteFileRejected::class)
        ->and($failure->reason())->toBe(RemoteFetchReason::Blocked);
    Http::assertNothingSent();
})->with([
    'rfc 1918' => ['10.0.0.5'],
    'loopback' => ['127.0.0.1'],
    'metadata' => ['169.254.169.254'],
    'ipv6 loopback' => ['::1'],
]);

it('refuses a host when any one of its pinned addresses is private', function (): void {
    MediaLibrary::fakeDns()->resolve('mixed.example', ['93.184.216.34', '10.0.0.5']);
    Http::fake();

    expect(dnsFakeFailure(fn () => MediaLibrary::remote()->fetch('https://mixed.example/'))->reason())
        ->toBe(RemoteFetchReason::Blocked);
    Http::assertNothingSent();
});

it('treats a name it was not given as unresolvable, so no test reaches real DNS', function (): void {
    MediaLibrary::fakeDns()->resolve('docs.example', '93.184.216.34');
    Http::fake();

    $failure = dnsFakeFailure(fn () => MediaLibrary::remote()->fetch('https://elsewhere.example/'));

    expect($failure)->toBeInstanceOf(RemoteFileUnavailable::class)
        ->and($failure->reason())->toBe(RemoteFetchReason::Unresolved)
        ->and($failure->retryable())->toBeTrue();
    Http::assertNothingSent();
});

it('makes every name unresolvable when nothing is pinned', function (): void {
    MediaLibrary::fakeDns();

    expect(dnsFakeFailure(fn () => MediaLibrary::remote()->vet('https://example.com/'))->reason())
        ->toBe(RemoteFetchReason::Unresolved);
});

it('turns a pinned name unresolvable again with unresolvable()', function (): void {
    $dns = MediaLibrary::fakeDns()->resolve('docs.example', '93.184.216.34');

    expect($dns->unresolvable('docs.example'))->toBe($dns)
        ->and(dnsFakeFailure(fn () => MediaLibrary::remote()->vet('https://docs.example/'))->reason())
        ->toBe(RemoteFetchReason::Unresolved);
});

it('takes a table of records, like Http::fake()', function (): void {
    MediaLibrary::fakeDns([
        'docs.example' => ['93.184.216.34', '2606:2800:0220:0001:0248:1893:25c8:1946'],
        'cdn.example' => '151.101.1.1',
        'gone.example' => [],
    ]);

    expect(MediaLibrary::remote()->vet('https://docs.example/')->addresses)
        ->toBe(['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946'])
        ->and(MediaLibrary::remote()->vet('https://cdn.example/')->addresses)->toBe(['151.101.1.1'])
        ->and(dnsFakeFailure(fn () => MediaLibrary::remote()->vet('https://gone.example/'))->reason())
        ->toBe(RemoteFetchReason::Unresolved);
});

it('keeps one fake per test: a second call adds to the pins of the first', function (): void {
    $first = MediaLibrary::fakeDns(['docs.example' => '93.184.216.34']);
    $second = MediaLibrary::fakeDns(['cdn.example' => '151.101.1.1']);

    expect($second)->toBe($first)
        ->and(MediaLibrary::remote()->vet('https://docs.example/')->addresses)->toBe(['93.184.216.34'])
        ->and(MediaLibrary::remote()->vet('https://cdn.example/')->addresses)->toBe(['151.101.1.1']);
});

it('lets a later pin replace an earlier one for the same name', function (): void {
    MediaLibrary::fakeDns(['docs.example' => '93.184.216.34'])->resolve('docs.example', ['151.101.1.1', '151.101.1.1']);

    expect(MediaLibrary::remote()->vet('https://docs.example/')->addresses)->toBe(['151.101.1.1']);
});

it('matches names the way the guard writes them: any case, a trailing dot', function (): void {
    MediaLibrary::fakeDns()->resolve('Docs.EXAMPLE.', '93.184.216.34');

    expect(MediaLibrary::remote()->vet('https://docs.example/')->addresses)->toBe(['93.184.216.34'])
        ->and(MediaLibrary::remote()->vet('https://DOCS.example./')->addresses)->toBe(['93.184.216.34']);
});

it('pins an internationalised name under its punycode form', function (): void {
    MediaLibrary::fakeDns()->resolve('bücher.example', '93.184.216.34');

    expect(MediaLibrary::remote()->vet('https://xn--bcher-kva.example/')->addresses)->toBe(['93.184.216.34'])
        ->and(MediaLibrary::remote()->vet('https://bücher.example/')->addresses)->toBe(['93.184.216.34']);
})->skip(! function_exists('idn_to_ascii'), 'needs ext-intl');

it('refuses a pin it could never be asked for, or an address that is not one', function (string $host, mixed $addresses, string $message): void {
    expect(fn () => MediaLibrary::fakeDns()->resolve($host, $addresses))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'an address literal' => ['93.184.216.34', '93.184.216.34', 'address'],
    'a bracketed ipv6 literal' => ['[::1]', '93.184.216.34', 'host name'],
    'a port' => ['docs.example:8080', '93.184.216.34', 'host name'],
    'a path' => ['docs.example/x', '93.184.216.34', 'host name'],
    'credentials' => ['user@docs.example', '93.184.216.34', 'host name'],
    'an empty name' => ['', '93.184.216.34', 'host name'],
    'a name the guard refuses' => ['docs..example', '93.184.216.34', 'host name'],
    'no address' => ['docs.example', [], 'unresolvable()'],
    'not an address' => ['docs.example', 'docs.example', 'not an IP address'],
    'a bracketed address' => ['docs.example', '[::1]', 'not an IP address'],
    'a non-string address' => ['docs.example', [42], 'not an IP address'],
]);

it('refuses an invalid name in unresolvable() too', function (): void {
    expect(fn () => MediaLibrary::fakeDns()->unresolvable('10.0.0.1'))->toThrow(InvalidArgumentException::class);
});

it('refuses an all-digit name in the table with the same message, not a TypeError', function (): void {
    expect(fn () => MediaLibrary::fakeDns(['123' => '93.184.216.34']))
        ->toThrow(InvalidArgumentException::class, 'Cannot pin [123]: not a host name the guard would look up.');
});

it('answers addFromUrl() through the same table', function (): void {
    MediaLibrary::fakeDns(['cdn.example' => '151.101.1.1']);
    Http::fake(['cdn.example/*' => Http::response('bytes', 200, ['Content-Type' => 'text/plain'])]);

    $media = MediaLibrary::addFromUrl('https://cdn.example/notes.txt')->toBucket('downloads');

    Storage::disk('public')->assertExists($media->getPath());
    expect(fn () => MediaLibrary::addFromUrl('https://unknown.example/notes.txt'))
        ->toThrow(RemoteFileRejected::class);
});

it('answers the SafeRemoteUrl rule through the same table', function (): void {
    MediaLibrary::fakeDns(['docs.example' => '93.184.216.34', 'intranet.example' => '10.0.0.5']);

    $passes = static fn (string $url): bool => Validator::make(['url' => $url], ['url' => [new SafeRemoteUrl]])->passes();

    expect($passes('https://docs.example/a'))->toBeTrue()
        ->and($passes('https://intranet.example/a'))->toBeFalse()
        ->and($passes('https://unknown.example/a'))->toBeFalse();
});

it('works next to MediaLibrary::fake(), in either order', function (bool $dnsFirst): void {
    if ($dnsFirst) {
        MediaLibrary::fakeDns(['cdn.example' => '151.101.1.1']);
        $fake = MediaLibrary::fake();
    } else {
        $fake = MediaLibrary::fake();
        MediaLibrary::fakeDns(['cdn.example' => '151.101.1.1']);
    }

    Http::fake(['cdn.example/*' => Http::response('bytes', 200, ['Content-Type' => 'text/plain'])]);

    expect(MediaLibrary::remote()->fetch('https://cdn.example/a.txt')->body)->toBe('bytes');

    MediaLibrary::addFromUrl('https://cdn.example/a.txt')->toBucket('downloads');

    $fake->assertAdded('downloads');
    expect(fn () => MediaLibrary::remote()->fetch('https://unknown.example/'))->toThrow(RemoteFileUnavailable::class);
})->with(['dns first' => [true], 'fake first' => [false]]);

it('never looks up an address literal', function (): void {
    $dns = MediaLibrary::fakeDns();
    Http::fake(['*' => Http::response('ok', 200)]);

    expect(MediaLibrary::remote()->fetch('https://93.184.216.34/')->body)->toBe('ok');
    $dns->assertNothingLookedUp();
});

// --- recorded lookups ---

it('records every name the guard looked up, in order', function (): void {
    $dns = MediaLibrary::fakeDns(['docs.example' => '93.184.216.34']);

    MediaLibrary::remote()->vet('https://docs.example/a');
    MediaLibrary::remote()->vet('https://DOCS.example/b');
    dnsFakeFailure(fn () => MediaLibrary::remote()->vet('https://unknown.example/'));

    expect($dns->lookups())->toBe(['docs.example', 'docs.example', 'unknown.example']);

    $dns->assertLookedUp('docs.example');
    $dns->assertLookedUp('Docs.Example.', times: 2);
    $dns->assertLookedUp('unknown.example', times: 1);
    $dns->assertNotLookedUp('cdn.example');
});

it('fails assertLookedUp() for a name never looked up, or looked up a different number of times', function (): void {
    $dns = MediaLibrary::fakeDns(['docs.example' => '93.184.216.34']);
    MediaLibrary::remote()->vet('https://docs.example/');

    expect(fn () => $dns->assertLookedUp('cdn.example'))
        ->toThrow(ExpectationFailedException::class, 'Expected [cdn.example] to be looked up, but it was not.')
        ->and(fn () => $dns->assertLookedUp('docs.example', times: 2))
        ->toThrow(ExpectationFailedException::class, 'Expected [docs.example] to be looked up 2 time(s), but it was looked up 1 time(s).');
});

it('fails assertNotLookedUp() and assertNothingLookedUp() once a name was looked up', function (): void {
    $dns = MediaLibrary::fakeDns();
    $dns->assertNothingLookedUp();
    $dns->assertNotLookedUp('docs.example');

    dnsFakeFailure(fn () => MediaLibrary::remote()->vet('https://docs.example/'));

    expect(fn () => $dns->assertNotLookedUp('docs.example'))
        ->toThrow(ExpectationFailedException::class, 'Expected [docs.example] not to be looked up, but it was looked up 1 time(s).')
        ->and(fn () => $dns->assertNothingLookedUp())
        ->toThrow(ExpectationFailedException::class, 'Expected no DNS lookups, but 1 were made: docs.example.');
});

// --- scope: one test's pins never reach the next ---

it('leaves pins behind in the container at the end of a test', function (): void {
    $dns = MediaLibrary::fakeDns(['left-behind.example' => '93.184.216.34']);
    MediaLibrary::remote()->vet('https://left-behind.example/');

    DnsFakeScope::$previous = $dns;

    expect(app(DnsFake::class))->toBe($dns);
});

it('starts the next test with no fake, no pins and no recorded lookups', function (): void {
    expect(app()->bound(DnsFake::class))->toBeFalse();

    $dns = MediaLibrary::fakeDns();

    expect($dns)->not->toBe(DnsFakeScope::$previous)
        ->and($dns->lookups())->toBe([])
        ->and(dnsFakeFailure(fn () => MediaLibrary::remote()->vet('https://left-behind.example/'))->reason())
        ->toBe(RemoteFetchReason::Unresolved);
});

/** Hands the first scope test's fake to the second, which runs in a fresh application. */
final class DnsFakeScope
{
    public static ?DnsFake $previous = null;
}
