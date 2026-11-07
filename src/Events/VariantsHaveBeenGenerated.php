<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * Fired once per generation run, after the media row has been saved with the new records.
 *
 * `size` sums the bytes written for `variants`; `freedBytes` sums the recorded sizes of the files
 * those renders replaced (see {@see VariantHasBeenGenerated}). `delta()` is the run's change in
 * stored bytes.
 */
final class VariantsHaveBeenGenerated
{
    use Dispatchable;

    /**
     * @param  list<string>  $variants
     */
    public function __construct(
        public readonly Media $media,
        public readonly array $variants,
        public readonly int $size = 0,
        public readonly int $freedBytes = 0,
    ) {}

    public function delta(): int
    {
        return $this->size - $this->freedBytes;
    }
}
