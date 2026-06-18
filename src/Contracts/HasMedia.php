<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Contracts;

use RoundlyConsulting\MediaLibrary\Buckets\MediaBucket;

interface HasMedia
{
    /** Host hook: declare the model's media buckets. */
    public function registerMediaBuckets(): void;

    public function addMediaBucket(string $name): MediaBucket;

    /** Run the host hook and return the named bucket's definition (or null if undeclared). */
    public function resolveMediaBucket(string $name): ?MediaBucket;
}
