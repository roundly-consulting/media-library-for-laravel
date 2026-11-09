<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Enums;

/** How a browser should treat a served file: render it (`inline`) or save it (`attachment`). */
enum ContentDisposition: string
{
    case Inline = 'inline';

    case Attachment = 'attachment';
}
