<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

final class MediaIsNotAnImage extends MediaLibraryException
{
    public static function forResponsiveImage(string $uuid): self
    {
        return new self("Responsive images can only be generated for image media; media [{$uuid}] is not an image.");
    }
}
