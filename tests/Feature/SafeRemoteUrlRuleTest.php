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

it('fails every unsafe url with one message, whatever the reason', function (mixed $url): void {
    // One message for every refusal: telling "private" from "not found" would tell a user which
    // internal names exist. The exception behind it keeps the exact reason.
    expect(safeUrlErrors($url))->toBe(['The source field must be a public http or https URL that can be reached.']);
})->with([
    'private' => ['http://inward.example/'],
    'loopback literal' => ['http://127.0.0.1/'],
    'scheme' => ['ftp://public.example/'],
    'credentials' => ['https://user:pw@public.example/'],
    'numeric host' => ['http://2130706433/'],
    'port' => ['http://public.example:0/'],
    'unresolved' => ['http://nowhere.example/'],
    'not a url' => ['not a url'],
    'not a string' => [['https://public.example/']],
]);

it('speaks slovak', function (): void {
    app()->setLocale('sk');

    expect(safeUrlErrors('http://inward.example/'))->toBe(['Pole source musí byť verejná a dostupná URL adresa s protokolom http alebo https.']);
});

it('vets through the facade root, so a fake still judges', function (): void {
    MediaLibrary::fake();

    expect(safeUrlErrors('http://inward.example/'))->not->toBe([])
        ->and(safeUrlErrors('https://public.example/'))->toBe([]);
});
