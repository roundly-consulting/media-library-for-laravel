<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\DataTransferObjects\VettedUrl;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFetchFailed;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Support\HostResolver;
use RoundlyConsulting\MediaLibrary\Support\PrivateNetworks;
use RoundlyConsulting\MediaLibrary\Support\RedirectLocation;

/*
 * The blocked-network contract. This file is the reference: `tests/Fixtures/contract/
 * blocked-networks.json` is copied byte-identical into the Node twin (safe-fetch-for-node,
 * `tests/goldens/contract/`) and `./parity.sh safe-fetch` fails the moment the two differ. Both
 * sides run every vector, so the PHP guard and the Node guard cannot disagree about what a
 * server may dial — the drift the hand-copied cosmos tables once had, unseen by either suite.
 *
 * The URL vectors run with the default guard (no allowlist, any port) and the fixture's own DNS:
 * a name it does not list does not resolve.
 */

/** @return array<string, mixed> */
function blockedNetworksContract(): array
{
    return json_decode((string) file_get_contents(__DIR__.'/../Fixtures/contract/blocked-networks.json'), true, flags: JSON_THROW_ON_ERROR);
}

it('holds exactly the contract table', function (): void {
    $contract = blockedNetworksContract();
    $sorted = static function (array $list): array {
        sort($list);

        return $list;
    };

    expect($contract['version'])->toBe(1)
        ->and($sorted(PrivateNetworks::BLOCKED))->toBe($sorted($contract['blocked_ranges']))
        ->and(PrivateNetworks::IPV4_EMBEDDING)->toBe($contract['ipv4_embedding'])
        ->and($sorted(PrivateNetworks::BLOCKED_HOSTNAMES))->toBe($sorted($contract['blocked_hostnames']))
        ->and($sorted(PrivateNetworks::BLOCKED_HOSTNAME_SUFFIXES))->toBe($sorted($contract['blocked_hostname_suffixes']));
});

it('judges every contract address', function (string $address, bool $blocked): void {
    expect(PrivateNetworks::isPrivate($address))->toBe($blocked)
        ->and(MediaLibrary::remote()->isPrivateAddress($address))->toBe($blocked);
})->with(function (): array {
    $vectors = [];

    foreach (blockedNetworksContract()['addresses'] as $vector) {
        $vectors['"'.$vector['address'].'"'] = [$vector['address'], $vector['blocked']];
    }

    return $vectors;
});

it('gives every contract url its verdict', function (string $url, string $verdict): void {
    $dns = blockedNetworksContract()['dns'];
    app()->instance(HostResolver::class, new HostResolver(static fn (string $host): array => $dns[$host] ?? []));

    try {
        $vetted = MediaLibrary::remote()->vet($url);
        $actual = 'ok';
    } catch (RemoteFetchFailed $exception) {
        $actual = $exception->reason()->value;
    }

    expect($actual)->toBe($verdict);

    if ($actual === 'ok') {
        expect($vetted)->toBeInstanceOf(VettedUrl::class);
    }
})->with(function (): array {
    $vectors = [];

    foreach (blockedNetworksContract()['urls'] as $vector) {
        $vectors['"'.$vector['url'].'"'] = [$vector['url'], $vector['verdict']];
    }

    return $vectors;
});

it('follows the same redirect statuses', function (): void {
    expect(RedirectLocation::STATUSES)->toBe(blockedNetworksContract()['redirect_statuses']);
});

it('resolves every contract redirect location the same way', function (string $base, string $location, ?string $next, ?string $refused): void {
    try {
        $actual = RedirectLocation::resolve($base, $location);
    } catch (RemoteFetchFailed $exception) {
        $actual = null;
        expect($exception->reason()->value)->toBe($refused);
    }

    expect($actual)->toBe($next);
})->with(function (): array {
    $vectors = [];

    foreach (blockedNetworksContract()['redirects'] as $vector) {
        $vectors[json_encode([$vector['base'], $vector['location']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)] = [
            $vector['base'], $vector['location'], $vector['next'] ?? null, $vector['refused'] ?? null,
        ];
    }

    return $vectors;
});

it('covers every verdict and the edges the plan asks for', function (): void {
    $contract = blockedNetworksContract();
    $verdicts = array_unique(array_column($contract['urls'], 'verdict'));
    sort($verdicts);

    expect($verdicts)->toBe(['blocked', 'credentials', 'invalid_url', 'non_canonical_host', 'ok', 'port', 'scheme', 'unresolved'])
        ->and(count($contract['addresses']))->toBeGreaterThanOrEqual(100)
        ->and(array_filter($contract['addresses'], static fn (array $vector): bool => $vector['blocked']))->not->toBeEmpty()
        ->and(array_filter($contract['addresses'], static fn (array $vector): bool => ! $vector['blocked']))->not->toBeEmpty();
});
