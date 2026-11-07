<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\DataTransferObjects;

/**
 * What a variant-size backfill did for one media: the variants whose size it recorded (or, on a
 * dry run, would record), their total bytes, and the variants whose file it could not measure —
 * missing or unreadable — which keep an unknown size.
 */
final readonly class VariantSizeBackfill
{
    /**
     * @param  list<string>  $recorded
     * @param  list<string>  $unreadable
     */
    public function __construct(
        public array $recorded,
        public int $bytes,
        public array $unreadable,
    ) {}
}
