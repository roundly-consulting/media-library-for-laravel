<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\DataTransferObjects;

/**
 * The bytes a set of media stores, from `MediaLibrary::usage()`.
 *
 * - `originalBytes` — each physical original once, however many rows point at it (a deduplicated
 *   upload, an `attach()`, a same-disk `copy()`): one file per distinct `(disk, path)`.
 * - `variantBytes` — every recorded variant size. Variants are per-row files, never shared; one
 *   recorded before sizes were counts 0 until `media:backfill-variant-sizes` measures it.
 * - `referencedOriginalBytes` — the originals counted once per row, the sum of each row's `size`.
 *
 * `totalBytes()` is what the disks hold; `savedBytes()` is what sharing originals saves.
 */
final readonly class StorageUsage
{
    public function __construct(
        public int $originalBytes,
        public int $variantBytes,
        public int $referencedOriginalBytes,
    ) {}

    public function totalBytes(): int
    {
        return $this->originalBytes + $this->variantBytes;
    }

    public function savedBytes(): int
    {
        return $this->referencedOriginalBytes - $this->originalBytes;
    }
}
