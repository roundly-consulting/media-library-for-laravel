<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Exceptions\DiskDoesNotExist;
use RoundlyConsulting\MediaLibrary\Exceptions\FileDoesNotExist;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\MediaLibrary\Exceptions\InvalidBase64Data;
use RoundlyConsulting\MediaLibrary\Exceptions\InvalidVariant;
use RoundlyConsulting\MediaLibrary\Exceptions\MediaLibraryException;
use RoundlyConsulting\MediaLibrary\Exceptions\VariantDriverUnavailable;

it('builds a file-does-not-exist exception', function (): void {
    expect(FileDoesNotExist::forPath('/x/y.png'))
        ->toBeInstanceOf(MediaLibraryException::class)
        ->and(FileDoesNotExist::forPath('/x/y.png')->getMessage())->toContain('/x/y.png')
        ->and(FileDoesNotExist::onDisk('a.png', 'cold')->getMessage())->toContain('cold');
});

it('builds a file-unacceptable exception', function (): void {
    $e = FileUnacceptableForBucket::mimeType('image/gif', 'avatar');

    expect($e)->toBeInstanceOf(MediaLibraryException::class)
        ->and($e->getMessage())->toContain('image/gif')
        ->and($e->getMessage())->toContain('avatar');
});

it('builds a disk-does-not-exist exception', function (): void {
    expect(DiskDoesNotExist::named('ghost')->getMessage())->toContain('ghost');
});

it('builds an invalid-base64 exception', function (): void {
    expect(InvalidBase64Data::make())->toBeInstanceOf(MediaLibraryException::class);
});

it('builds invalid-variant exceptions', function (): void {
    expect(InvalidVariant::unknownName('thumb'))->toBeInstanceOf(MediaLibraryException::class)
        ->and(InvalidVariant::unknownName('thumb')->getMessage())->toContain('thumb')
        ->and(InvalidVariant::unsupportedFormat('avif', 'gd')->getMessage())->toContain('avif')
        ->and(InvalidVariant::invalidFitMode('squish')->getMessage())->toContain('squish')
        ->and(InvalidVariant::invalidQuality(0)->getMessage())->toContain('0')
        ->and(InvalidVariant::notGenerated('thumb')->getMessage())->toContain('thumb');
});

it('builds a variant-driver-unavailable exception', function (): void {
    expect(VariantDriverUnavailable::noExtension())
        ->toBeInstanceOf(MediaLibraryException::class)
        ->and(VariantDriverUnavailable::noExtension()->getMessage())->toContain('imagick');
});
