<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\DataTransferObjects\GeneratedVariant;

it('round-trips its stored payload', function (): void {
    $variant = new GeneratedVariant('thumb.webp', 'webp', 'hot');

    expect(GeneratedVariant::fromStored($variant->toArray()))->toEqual($variant)
        ->and($variant->onDisk('cold')->disk)->toBe('cold')
        ->and($variant->onDisk('cold')->fileName)->toBe('thumb.webp');
});

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
