<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

/** A disk was asked for a permanent public URL it cannot build without leaking an internal host. */
final class DiskHasNoPublicUrl extends MediaLibraryException
{
    public static function presignedSeparately(string $disk): self
    {
        return new self(
            "The [{$disk}] disk has no public URL: it presigns for a separate endpoint and sets no `url`, "
            .'so an unsigned URL would name the in-cluster endpoint. Use a temporary URL, or set '
            ."filesystems.disks.{$disk}.url to a public base URL."
        );
    }
}
