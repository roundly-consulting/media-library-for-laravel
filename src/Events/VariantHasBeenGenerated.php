<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\MediaLibrary\Models\Media;

final class VariantHasBeenGenerated
{
    use Dispatchable;

    public function __construct(
        public readonly Media $media,
        public readonly string $variant,
    ) {}
}
