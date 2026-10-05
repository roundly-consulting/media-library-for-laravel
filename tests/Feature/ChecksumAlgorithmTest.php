<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Enums\ChecksumAlgorithm;
use RoundlyConsulting\MediaLibrary\Exceptions\ChecksumMismatch;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\Checksum;
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

/*
 * Nothing records which algorithm a row's checksum was taken with. Verifying with whatever the
 * config says today turned every stored file into "drifted" the moment the setting changed — and,
 * with verify_checksum_on_read, made every one of them unreadable.
 */
it('still verifies and streams media stored before the checksum algorithm changed', function (string $switchedTo): void {
    $media = MediaLibrary::add(__DIR__.'/../files/sunrise.png')->toBucket('brand');

    config()->set('media.checksum_algorithm', $switchedTo);
    config()->set('media.verify_checksum_on_read', true);

    $fresh = Media::query()->findOrFail($media->id);
    $stream = $fresh->getStream();

    expect($fresh->verifyIntegrity())->toBeTrue()
        ->and(is_resource($stream))->toBeTrue()
        ->and(Artisan::call('media:verify'))->toBe(0);

    fclose($stream);
})->with(['sha512', 'sha3-256', 'sha384']);

it('still detects drift after the checksum algorithm changed', function (): void {
    $media = MediaLibrary::add(__DIR__.'/../files/sunrise.png')->toBucket('brand');
    Storage::disk('public')->put($media->getPath(), 'tampered');

    config()->set('media.checksum_algorithm', 'sha512');
    config()->set('media.verify_checksum_on_read', true);

    $fresh = Media::query()->findOrFail($media->id);

    expect($fresh->verifyIntegrity())->toBeFalse()
        ->and(fn () => $fresh->getStream())->toThrow(ChecksumMismatch::class);
});

it('knows the digest length of every accepted algorithm', function (ChecksumAlgorithm $algorithm): void {
    expect($algorithm->digestLength())->toBe(strlen(hash($algorithm->value, 'media')));
})->with(ChecksumAlgorithm::cases());

it('hashes a stored original with the configured algorithm', function (): void {
    $media = MediaLibrary::add(__DIR__.'/../files/sunrise.png')->toBucket('brand');

    expect(app(Checksum::class)->forStoredOriginal($media))->toBe($media->checksum);

    config()->set('media.checksum_algorithm', 'sha512');

    expect(app(Checksum::class)->forStoredOriginal($media))->toBe(hash_file('sha512', __DIR__.'/../files/sunrise.png'));

    Storage::disk('public')->delete($media->getPath());

    expect(app(Checksum::class)->forStoredOriginal($media))->toBeNull();
});

it('matches nothing without a recorded checksum or a stored file', function (): void {
    $media = MediaLibrary::add(__DIR__.'/../files/sunrise.png')->toBucket('brand');
    $checksum = app(Checksum::class);

    $unrecorded = $media->replicate();
    $unrecorded->checksum = null;

    expect($checksum->matchesStoredOriginal($unrecorded))->toBeFalse();

    Storage::disk('public')->delete($media->getPath());

    expect($checksum->matchesStoredOriginal($media))->toBeFalse();
});
