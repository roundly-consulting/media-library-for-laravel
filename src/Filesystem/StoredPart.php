<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Filesystem;

/**
 * One uploaded part of a multipart upload, as the store lists it.
 *
 * @internal
 */
final readonly class StoredPart
{
    public function __construct(
        public int $number,
        public int $size,
        public string $etag,
    ) {}
}
