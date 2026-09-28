<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

it('stores a checksum of every supported algorithm', function (string $algorithm, int $length): void {
    config()->set('media.checksum_algorithm', $algorithm);

    $media = MediaLibrary::add(__DIR__.'/../files/pixel.png')->toBucket('brand');

    expect($media->fresh()?->checksum)->toHaveLength($length)
        ->and($media->fresh()?->verifyIntegrity())->toBeTrue();
})->with([
    ['sha256', 64],
    ['sha384', 96],
    ['sha512', 128],
    ['sha3-512', 128],
]);

it('declares a checksum column wide enough for a 512-bit digest', function (): void {
    $source = (string) file_get_contents(__DIR__.'/../../database/migrations/0001_01_01_000000_create_media_table.php');

    expect($source)->toContain("string('checksum', 128)");
});

it('refuses a checksum algorithm too weak to deduplicate safely', function (string $algorithm): void {
    config()->set('media.checksum_algorithm', $algorithm);

    expect(fn () => MediaLibrary::add(__DIR__.'/../files/pixel.png')->toBucket('brand'))
        ->toThrow(InvalidConfigurationException::class, 'media.checksum_algorithm');
})->with(['md5', 'crc32b', 'not-an-algorithm']);
