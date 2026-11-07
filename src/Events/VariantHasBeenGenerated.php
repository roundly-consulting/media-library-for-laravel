<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * Fired after one variant file has been written and recorded on the media (before the row is saved).
 *
 * `size` is the bytes written. `freedBytes` is the recorded size of the file this render replaced
 * — a re-render (`regenerate(force: true)`, a queued job) overwrites the variant or removes its
 * previous file — and 0 on a first render or when the replaced record has no size. `delta()` is
 * the change in stored bytes.
 */
final class VariantHasBeenGenerated
{
    use Dispatchable;

    public function __construct(
        public readonly Media $media,
        public readonly string $variant,
        public readonly int $size = 0,
        public readonly int $freedBytes = 0,
    ) {}

    public function delta(): int
    {
        return $this->size - $this->freedBytes;
    }
}
