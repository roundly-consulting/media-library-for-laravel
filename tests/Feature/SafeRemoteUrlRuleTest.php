<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Rules\SafeRemoteUrl;
use RoundlyConsulting\MediaLibrary\Support\HostResolver;

/*
 * The input-time half of the guard: a URL a user hands in is refused with a translated message
 * before anything stores it, on the same verdicts `vet()` and `fetch()` apply.
 */

beforeEach(function (): void {
    app()->instance(HostResolver::class, new HostResolver(static fn (string $host): array => match ($host) {
        'public.example' => ['93.184.216.34'],
        'inward.example' => ['10.0.0.5'],
        default => [],
    }));
});

function safeUrlErrors(mixed $url): array
{
    return Validator::make(['source' => $url], ['source' => ['required', new SafeRemoteUrl]])->errors()->get('source');
}

it('passes a public url', function (): void {
    expect(safeUrlErrors('https://public.example/doc'))->toBe([]);
});

it('fails an unsafe url with its translated reason', function (mixed $url, string $message): void {
    expect(safeUrlErrors($url))->toBe([$message]);
})->with([
    'private' => ['http://inward.example/', 'The source field must not point at a private or reserved network address.'],
    'loopback literal' => ['http://127.0.0.1/', 'The source field must not point at a private or reserved network address.'],
    'scheme' => ['ftp://public.example/', 'The source field must use http or https.'],
    'credentials' => ['https://user:pw@public.example/', 'The source field must not contain a user name or password.'],
    'numeric host' => ['http://2130706433/', 'The source field must name its host by a domain name or a standard IP address.'],
    'port' => ['http://public.example:0/', 'The source field uses a port that is not allowed.'],
    'unresolved' => ['http://nowhere.example/', 'The host in the source field could not be found.'],
    'not a url' => ['not a url', 'The source field must be a valid http or https URL.'],
    'not a string' => [['https://public.example/'], 'The source field must be a valid http or https URL.'],
]);

it('speaks slovak', function (): void {
    app()->setLocale('sk');

    expect(safeUrlErrors('http://inward.example/'))->toBe(['Pole source nesmie smerovať na súkromnú ani vyhradenú sieťovú adresu.']);
});

it('vets through the facade root, so a fake still judges', function (): void {
    MediaLibrary::fake();

    expect(safeUrlErrors('http://inward.example/'))->not->toBe([])
        ->and(safeUrlErrors('https://public.example/'))->toBe([]);
});
