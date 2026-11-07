<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\DataTransferObjects;

use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * What was actually written for one generated variant — its file name inside the media's
 * variants directory, the output format, the disk it landed on and how many bytes were written.
 * Stored per variant in {@see Media::$generated_variants}, so every read, move, copy and delete
 * resolves the file that exists instead of re-deriving a name from the original's extension or
 * the current bucket, and storage can be metered without asking the disk.
 *
 * `size` is null for a record written before sizes were recorded; `media:backfill-variant-sizes`
 * measures those from the disk.
 */
final readonly class GeneratedVariant
{
    public function __construct(
        public string $fileName,
        public string $format,
        public string $disk,
        public ?int $size = null,
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
        $size = $value['size'] ?? null;

        if (! is_string($fileName) || $fileName === '' || ! is_string($disk) || $disk === '') {
            return null;
        }

        return new self(
            $fileName,
            is_string($format) ? $format : '',
            $disk,
            is_int($size) && $size >= 0 ? $size : null,
        );
    }

    public function onDisk(string $disk): self
    {
        return new self($this->fileName, $this->format, $disk, $this->size);
    }

    public function withSize(int $size): self
    {
        return new self($this->fileName, $this->format, $this->disk, $size);
    }

    /**
     * The stored (json column) payload. A record without a known size keeps the shape it was
     * stored with — no `size` key — so rewriting an old record never invents one.
     *
     * @return array{file_name: string, format: string, disk: string, size?: int}
     */
    public function toArray(): array
    {
        $stored = [
            'file_name' => $this->fileName,
            'format' => $this->format,
            'disk' => $this->disk,
        ];

        if ($this->size !== null) {
            $stored['size'] = $this->size;
        }

        return $stored;
    }
}
