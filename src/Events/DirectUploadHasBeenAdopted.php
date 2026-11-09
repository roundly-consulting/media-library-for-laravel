<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Models\MediaUpload;

/**
 * A direct upload became media. `deduplicated`: identical content already stored in the dedup
 * scope was shared, and the uploaded object is removed once the adoption commits.
 */
final class DirectUploadHasBeenAdopted
{
    use Dispatchable;

    public function __construct(
        public readonly Media $media,
        public readonly MediaUpload $upload,
        public readonly bool $deduplicated,
    ) {}
}
