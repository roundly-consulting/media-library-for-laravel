<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\DataTransferObjects;

/**
 * A normalized, already-materialized source file ready to be stored.
 *
 * Every adder (`addMediaFromUrl`, `addMediaFromString`, …) resolves the source down to a
 * readable local path plus its detected metadata, so the storage layer has a single shape
 * to work with regardless of where the bytes came from.
 */
final readonly class AddedFile
{
    public function __construct(
        public string $path,
        public string $name,
        public string $fileName,
        public ?string $mimeType,
        public ?string $extension,
        public int $size,
        public bool $isTemporary = false,
    ) {}
}
