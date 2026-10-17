<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Enums\RemoteFetchReason;
use RoundlyConsulting\MediaLibrary\Exceptions\MediaLibraryException;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFetchFailed;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFileRejected;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFileUnavailable;

/*
 * Status-aware remote errors: a caller retries what can heal (RemoteFileUnavailable) and gives up
 * on what cannot (RemoteFileRejected). Both share RemoteFetchFailed, so existing catches of
 * RemoteFileRejected and MediaLibraryException keep working.
 */

it('tells terminal rejections from retryable failures', function (RemoteFetchFailed $exception, bool $retryable, RemoteFetchReason $reason, ?int $status): void {
    expect($exception)->toBeInstanceOf(MediaLibraryException::class)
        ->and($exception->retryable())->toBe($retryable)
        ->and($exception->reason())->toBe($reason)
        ->and($exception->status())->toBe($status)
        ->and($exception->getMessage())->toContain('https://example.com/a');
})->with([
    'bad scheme' => [fn () => RemoteFileRejected::unsupportedScheme('https://example.com/a'), false, RemoteFetchReason::Scheme, null],
    'not a url' => [fn () => RemoteFileRejected::invalidUrl('https://example.com/a'), false, RemoteFetchReason::InvalidUrl, null],
    'credentials' => [fn () => RemoteFileRejected::credentials('https://example.com/a'), false, RemoteFetchReason::Credentials, null],
    'non-canonical host' => [fn () => RemoteFileRejected::nonCanonicalHost('https://example.com/a'), false, RemoteFetchReason::NonCanonicalHost, null],
    'idn without intl' => [fn () => RemoteFileRejected::internationalHostNeedsIntl('https://example.com/a'), false, RemoteFetchReason::NonCanonicalHost, null],
    'private address' => [fn () => RemoteFileRejected::privateAddress('https://example.com/a'), false, RemoteFetchReason::Blocked, null],
    'port' => [fn () => RemoteFileRejected::blockedPort('https://example.com/a'), false, RemoteFetchReason::Port, null],
    'unresolvable, as addFromUrl reports it' => [fn () => RemoteFileRejected::unresolvableHost('https://example.com/a'), false, RemoteFetchReason::Unresolved, null],
    'too many redirects' => [fn () => RemoteFileRejected::tooManyRedirects('https://example.com/a', 5), false, RemoteFetchReason::TooManyRedirects, null],
    'too large' => [fn () => RemoteFileRejected::tooLarge('https://example.com/a', 10), false, RemoteFetchReason::TooLarge, null],
    'cannot pin' => [fn () => RemoteFileRejected::cannotPin('https://example.com/a'), false, RemoteFetchReason::CannotPin, null],
    '404' => [fn () => RemoteFileRejected::failedStatus('https://example.com/a', 404), false, RemoteFetchReason::Status, 404],
    'tls' => [fn () => RemoteFileRejected::tlsFailed('https://example.com/a', 60), false, RemoteFetchReason::Tls, null],
    'unresolvable' => [fn () => RemoteFileUnavailable::unresolvable('https://example.com/a'), true, RemoteFetchReason::Unresolved, null],
    '503' => [fn () => RemoteFileUnavailable::failedStatus('https://example.com/a', 503), true, RemoteFetchReason::Status, 503],
    'connection' => [fn () => RemoteFileUnavailable::connectionFailed('https://example.com/a', 7), true, RemoteFetchReason::Connection, null],
    'timeout' => [fn () => RemoteFileUnavailable::timedOut('https://example.com/a', 30), true, RemoteFetchReason::Timeout, null],
]);

it('carries Retry-After on a retryable status', function (): void {
    expect(RemoteFileUnavailable::failedStatus('https://example.com/a', 429, 120)->retryAfter())->toBe(120)
        ->and(RemoteFileUnavailable::failedStatus('https://example.com/a', 503)->retryAfter())->toBeNull()
        ->and(RemoteFileRejected::failedStatus('https://example.com/a', 404)->retryAfter())->toBeNull();
});

it('names the curl error, never an address', function (): void {
    expect(RemoteFileUnavailable::connectionFailed('https://example.com/a', 7)->getMessage())->toContain('cURL error 7')
        ->and(RemoteFileUnavailable::connectionFailed('https://example.com/a')->getMessage())->not->toContain('cURL')
        ->and(RemoteFileRejected::tlsFailed('https://example.com/a', 60)->getMessage())->toContain('cURL error 60');
});

it('keeps user info and control characters out of every message', function (): void {
    $message = RemoteFileRejected::credentials("https://user:s3cr3t@example.com/a\r\nX-Injected: 1")->getMessage();

    expect($message)->not->toContain('s3cr3t')
        ->and($message)->not->toContain('user:')
        ->and($message)->not->toContain("\r")
        ->and($message)->not->toContain("\n")
        ->and($message)->toContain('https://***@example.com/a');
});

it('shortens a very long url in a message', function (): void {
    $message = RemoteFileRejected::invalidUrl('https://example.com/'.str_repeat('a', 5000))->getMessage();

    expect(strlen($message))->toBeLessThan(2300)
        ->and($message)->toContain('…');
});

it('reads a hand-made rejection as terminal and a hand-made unavailability as retryable', function (): void {
    expect((new RemoteFileRejected('custom'))->reason())->toBe(RemoteFetchReason::Blocked)
        ->and((new RemoteFileRejected('custom'))->retryable())->toBeFalse()
        ->and((new RemoteFileUnavailable('custom'))->reason())->toBe(RemoteFetchReason::Connection)
        ->and((new RemoteFileUnavailable('custom'))->retryable())->toBeTrue()
        ->and((new RemoteFileUnavailable('custom'))->status())->toBeNull();
});
