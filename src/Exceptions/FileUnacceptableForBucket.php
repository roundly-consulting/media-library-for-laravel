<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

final class FileUnacceptableForBucket extends MediaLibraryException
{
    public static function mimeType(string $mimeType, string $bucket): self
    {
        return new self(
            "The mime type [{$mimeType}] is not accepted by media bucket [{$bucket}]."
        );
    }

    public static function tooLarge(int $size, int $maxBytes, string $bucket): self
    {
        return new self(
            "The file ({$size} bytes) is larger than the {$maxBytes}-byte limit of media bucket [{$bucket}]."
        );
    }

    public static function dimensions(?int $width, ?int $height, string $bucket): self
    {
        $actual = $width === null || $height === null ? 'unreadable' : "{$width}x{$height}";

        return new self(
            "The image dimensions ({$actual}) are outside the dimensions media bucket [{$bucket}] accepts."
        );
    }

    /** A direct upload's bytes are not the ones whose sha256 the browser declared. */
    public static function checksumMismatch(string $bucket): self
    {
        return new self("The uploaded file does not match the checksum declared for media bucket [{$bucket}].");
    }

    /** The model a direct upload was issued for is gone. */
    public static function ownerGone(string $bucket): self
    {
        return new self("The owner of media bucket [{$bucket}] no longer exists.");
    }
}
