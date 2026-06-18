<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

final class InvalidVariant extends MediaLibraryException
{
    public static function unknownName(string $name): self
    {
        return new self("There is no variant named [{$name}] registered for this media.");
    }

    public static function unsupportedFormat(string $format, string $driver): self
    {
        return new self("The [{$driver}] image driver cannot produce the [{$format}] format.");
    }

    public static function invalidFitMode(string $mode): self
    {
        return new self("[{$mode}] is not a valid variant fit mode.");
    }

    public static function invalidQuality(int $quality): self
    {
        return new self("Variant quality must be between 1 and 100, [{$quality}] given.");
    }

    public static function notGenerated(string $name): self
    {
        return new self("The variant [{$name}] has not been generated for this media.");
    }
}
