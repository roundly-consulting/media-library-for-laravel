<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Support\HostResolver;
use RoundlyConsulting\MediaLibrary\Support\PrivateNetworks;

it('tells private and reserved addresses from public ones', function (string $address, bool $private): void {
    expect(PrivateNetworks::isPrivate($address))->toBe($private);
})->with([
    ['93.184.216.34', false],
    ['8.8.8.8', false],
    ['100.63.255.255', false],
    ['100.128.0.0', false],
    ['172.15.255.255', false],
    ['172.32.0.0', false],
    ['2606:2800:220:1:248:1893:25c8:1946', false],
    ['::ffff:93.184.216.34', false],
    ['64:ff9b::808:808', false],       // NAT64 of 8.8.8.8
    ['2002:5db8:d822::1', false],      // 6to4 of 93.184.216.34
    ['0.0.0.0', true],
    ['10.255.255.255', true],
    ['100.64.0.1', true],
    ['127.0.0.1', true],
    ['169.254.169.254', true],
    ['172.16.0.1', true],
    ['172.31.255.255', true],
    ['192.0.0.192', true],
    ['192.168.10.1', true],
    ['198.18.0.1', true],
    ['224.0.0.1', true],
    ['255.255.255.255', true],
    ['::', true],
    ['::1', true],
    ['::ffff:127.0.0.1', true],
    ['::ffff:169.254.169.254', true],
    ['::127.0.0.1', true],
    ['64:ff9b::a9fe:a9fe', true],      // NAT64 of 169.254.169.254
    ['64:ff9b:1::1', true],
    ['2002:7f00:1::1', true],          // 6to4 of 127.0.0.1
    ['2001:db8::1', true],
    ['fd00:ec2::254', true],
    ['fe80::1', true],
    ['fec0::1', true],
    ['ff02::1', true],
    ['not an address', true],
]);

it('matches allowlisted host names case-insensitively, never by address', function (): void {
    $allowlist = ['Minio.Internal', '10.0.0.0/8'];

    expect(PrivateNetworks::allowsHost('minio.internal', $allowlist))->toBeTrue()
        ->and(PrivateNetworks::allowsHost('other.internal', $allowlist))->toBeFalse()
        ->and(PrivateNetworks::allowsHost('10.0.0.0/8', $allowlist))->toBeFalse();
});

it('matches allowlisted addresses and ranges, IPv4-mapped included', function (string $address, bool $allowed): void {
    $allowlist = ['minio.internal', '10.0.0.0/8', '192.168.1.5', 'fd00::/8', '172.16.0.0/13'];

    expect(PrivateNetworks::allowsAddress($address, $allowlist))->toBe($allowed);
})->with([
    ['10.1.2.3', true],
    ['::ffff:10.1.2.3', true],
    ['192.168.1.5', true],
    ['192.168.1.6', false],
    ['fd12::1', true],
    ['fe80::1', false],
    ['172.23.255.255', true],
    ['172.24.0.0', false],
    ['garbage', false],
]);

it('validates allowlist ranges', function (string $entry, bool $valid): void {
    expect(PrivateNetworks::isValidRange($entry))->toBe($valid);
})->with([
    ['10.0.0.0/8', true],
    ['10.0.0.0/32', true],
    ['fd00::/8', true],
    ['::/128', true],
    ['10.0.0.0/33', false],
    ['fd00::/129', false],
    ['10.0.0.0/x', false],
    ['intranet/8', false],
    ['10.0.0.0', false],
]);

it('resolves through the system resolver when no lookup is given', function (): void {
    expect((new HostResolver)->resolve('localhost'))->toContain('127.0.0.1')
        ->and((new HostResolver)->resolve('media-pin.invalid'))->toBe([]);
});

it('is built by the container with the system resolver', function (): void {
    app()->forgetInstance(HostResolver::class);

    expect(app(HostResolver::class)->resolve('localhost'))->toContain('127.0.0.1');
});
