<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

final class MediaCannotBeStreamed extends MediaLibraryException
{
    public static function noPublicUrl(): self
    {
        return new self(
            'Private media has no public URL. Use getTemporaryUrl() to obtain a signed or presigned URL.'
        );
    }
}
