<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Events;

use Illuminate\Foundation\Events\Dispatchable;
use RoundlyConsulting\MediaLibrary\Models\MediaUpload;

/**
 * A direct upload failed its checks (type, size, dimensions) at adoption. The uploaded object is
 * deleted and the ticket is final; `reason` is the refusal's message.
 */
final class DirectUploadHasBeenRejected
{
    use Dispatchable;

    public function __construct(
        public readonly MediaUpload $upload,
        public readonly string $reason,
    ) {}
}
