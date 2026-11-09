<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\DataTransferObjects;

/**
 * A file already in storage, inspected where it lies, that an add adopts instead of writing:
 * its size, sniffed type, displayed dimensions and checksum were all read from storage.
 * `inPlace` keeps it at its own path (the ticket's final key); otherwise it is moved.
 */
final readonly class StoredObject
{
    public function __construct(
        public string $disk,
        public string $path,
        public int $size,
        public ?string $mimeType,
        public ?string $checksum,
        public ?int $width = null,
        public ?int $height = null,
        public bool $inPlace = false,
    ) {}
}
