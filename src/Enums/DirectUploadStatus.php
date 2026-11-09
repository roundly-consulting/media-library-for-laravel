<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Enums;

/** Where a direct-upload ticket stands. Every state but `pending` and `adopting` is final. */
enum DirectUploadStatus: string
{
    case Pending = 'pending';

    case Adopting = 'adopting';

    case Adopted = 'adopted';

    case Rejected = 'rejected';

    case Aborted = 'aborted';

    case Expired = 'expired';
}
