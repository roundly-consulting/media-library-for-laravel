<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Support\CappedFileStream;
use RoundlyConsulting\MediaLibrary\Support\SizeLimit;

it('writes up to its budget, then reports short writes instead of failing', function (): void {
    $path = (string) tempnam(sys_get_temp_dir(), 'media-capped-');
    $limit = new SizeLimit(10);
    $stream = CappedFileStream::open($path, $limit);

    try {
        expect($stream)->toBeResource()
            ->and(fwrite($stream, 'abcdef'))->toBe(6)
            ->and($limit->exceeded)->toBeFalse()
            ->and(fwrite($stream, 'ghijkl'))->toBe(4)
            ->and($limit->exceeded)->toBeTrue()
            ->and(fwrite($stream, 'more'))->toBe(0)
            ->and($limit->written)->toBe(10)
            ->and(fflush($stream))->toBeTrue()
            ->and(ftell($stream))->toBe(10)
            ->and(fstat($stream)['size'] ?? null)->toBe(10);

        rewind($stream);

        expect(fread($stream, 100))->toBe('abcdefghij')
            ->and(feof($stream))->toBeTrue();
    } finally {
        fclose($stream);
        @unlink($path);
    }
});

it('opens nothing it was not handed', function (): void {
    expect(@fopen('media-library-capped://unknown', 'w+b'))->toBeFalse()
        ->and(CappedFileStream::open(sys_get_temp_dir().'/missing-dir-'.uniqid().'/file', new SizeLimit(10)))->toBeFalse();
});
