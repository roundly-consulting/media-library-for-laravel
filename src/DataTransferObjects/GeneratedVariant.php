<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\DataTransferObjects;

use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * What was actually written for one generated variant — its file name inside the media's
 * variants directory, the output format and the disk it landed on. Stored per variant in
 * {@see Media::$generated_variants}, so every read, move, copy and delete resolves the file that
 * exists instead of re-deriving a name from the original's extension or the current bucket.
 */
final readonly class GeneratedVariant
{
    public function __construct(
        public string $fileName,
        public string $format,
        public string $disk,
    ) {}

    /** The record stored under a variant name, or null when the value is not a record. */
    public static function fromStored(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        $fileName = $value['file_name'] ?? null;
        $format = $value['format'] ?? null;
        $disk = $value['disk'] ?? null;

        if (! is_string($fileName) || $fileName === '' || ! is_string($disk) || $disk === '') {
            return null;
        }

        return new self($fileName, is_string($format) ? $format : '', $disk);
    }

    public function onDisk(string $disk): self
    {
        return new self($this->fileName, $this->format, $disk);
    }

    /**
     * The stored (json column) payload.
     *
     * @return array{file_name: string, format: string, disk: string}
     */
    public function toArray(): array
    {
        return [
            'file_name' => $this->fileName,
            'format' => $this->format,
            'disk' => $this->disk,
        ];
    }
}
