<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * Fired after a media's variant files have been removed: the media was permanently deleted, its
 * original was replaced (the variants are rebuilt from the new bytes), or it entered a bucket that
 * does not define them. Removals that wait for a transaction fire once it commits.
 *
 * `freedBytes` sums the recorded sizes of `variants`; a variant recorded before sizes were counts
 * 0. A re-render that replaces a variant reports its freed bytes on
 * {@see VariantHasBeenGenerated} instead.
 */
final class VariantsHaveBeenDeleted
{
    use Dispatchable;

    /**
     * @param  list<string>  $variants
     */
    public function __construct(
        public readonly Media $media,
        public readonly array $variants,
        public readonly int $freedBytes,
    ) {}
}
