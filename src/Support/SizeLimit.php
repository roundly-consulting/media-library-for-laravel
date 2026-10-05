<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

/**
 * The byte budget of one download, kept by the {@see CappedFileStream} it is written through:
 * how much went through, and whether the transfer tried to go past the cap.
 *
 * @internal
 */
final class SizeLimit
{
    public int $written = 0;

    public bool $exceeded = false;

    public function __construct(
        public readonly int $maxBytes,
    ) {}
}
