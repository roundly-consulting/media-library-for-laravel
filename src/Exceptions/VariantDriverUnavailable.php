<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

final class VariantDriverUnavailable extends MediaLibraryException
{
    public static function noExtension(): self
    {
        return new self(
            'No image driver is available: install the imagick or gd PHP extension to generate media variants.'
        );
    }
}
