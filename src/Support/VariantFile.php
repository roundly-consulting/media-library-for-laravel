<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

use RoundlyConsulting\MediaLibrary\DataTransferObjects\GeneratedVariant;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * One stored variant file a row is letting go of: which variant, where it lives and its recorded
 * size. Captured before the row changes, because a custom path layout may derive the variants
 * directory from the owner or bucket the row is about to leave.
 *
 * @internal
 */
final readonly class VariantFile
{
    public function __construct(
        public string $variant,
        public string $disk,
        public string $path,
        public ?int $size,
    ) {}

    public static function of(Media $media, string $variant, GeneratedVariant $record): self
    {
        return new self($variant, $record->disk, $media->getPathForVariantsDirectory().$record->fileName, $record->size);
    }
}
