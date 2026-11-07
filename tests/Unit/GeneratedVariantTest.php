<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\DataTransferObjects\GeneratedVariant;

it('round-trips its stored payload', function (): void {
    $variant = new GeneratedVariant('thumb.webp', 'webp', 'hot', 1234);

    expect(GeneratedVariant::fromStored($variant->toArray()))->toEqual($variant)
        ->and($variant->toArray())->toBe(['file_name' => 'thumb.webp', 'format' => 'webp', 'disk' => 'hot', 'size' => 1234])
        ->and($variant->onDisk('cold')->disk)->toBe('cold')
        ->and($variant->onDisk('cold')->fileName)->toBe('thumb.webp')
        ->and($variant->onDisk('cold')->size)->toBe(1234);
});

it('reads a record stored before sizes were as an unknown size, and writes it back unchanged', function (): void {
    $stored = ['file_name' => 'thumb.webp', 'format' => 'webp', 'disk' => 'hot'];

    $variant = GeneratedVariant::fromStored($stored);

    expect($variant?->size)->toBeNull()
        ->and($variant?->toArray())->toBe($stored)
        ->and($variant?->onDisk('cold')->toArray())->not->toHaveKey('size')
        ->and($variant?->withSize(0)->toArray())->toBe([...$stored, 'size' => 0]);
});

it('treats a size that is not a whole number of bytes as unknown', function (mixed $size): void {
    expect(GeneratedVariant::fromStored(['file_name' => 'thumb.webp', 'format' => 'webp', 'disk' => 'hot', 'size' => $size])?->size)
        ->toBeNull();
})->with([
    'null' => [null],
    'negative' => [-1],
    'string' => ['120'],
    'float' => [12.5],
]);

it('refuses anything that is not a complete record', function (mixed $stored): void {
    expect(GeneratedVariant::fromStored($stored))->toBeNull();
})->with([
    'legacy flag' => [true],
    'null' => [null],
    'no file name' => [['format' => 'webp', 'disk' => 'hot']],
    'empty file name' => [['file_name' => '', 'format' => 'webp', 'disk' => 'hot']],
    'no disk' => [['file_name' => 'thumb.webp', 'format' => 'webp']],
]);

it('tolerates a record without a format', function (): void {
    expect(GeneratedVariant::fromStored(['file_name' => 'thumb.webp', 'disk' => 'hot'])?->format)->toBe('');
});
