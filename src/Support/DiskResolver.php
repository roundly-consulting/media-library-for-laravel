<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

use RoundlyConsulting\MediaLibrary\Buckets\MediaBucket;
use RoundlyConsulting\MediaLibrary\Exceptions\DiskDoesNotExist;

/**
 * Resolves which disk a media's original and variants live on, following the §7 precedence,
 * and guards every resolved name against the host's configured filesystem disks.
 */
final class DiskResolver
{
    /**
     * Original disk — first match wins:
     *   1. explicit override (terminal `$disk` arg / `PendingFileAdd::useDisk()`)
     *   2. bucket `->useDisk()`
     *   3. `config('media.disk')` (default 'public')
     */
    public function resolveOriginalDisk(?string $override, ?MediaBucket $bucket): string
    {
        $disk = $override
            ?? $bucket?->getDisk()
            ?? $this->configDisk();

        $this->ensureDiskExists($disk);

        return $disk;
    }

    /**
     * Variants disk — first match wins:
     *   1. explicit override (`PendingFileAdd::storingVariantsOnDisk()`)
     *   2. bucket `->storingVariantsOnDisk()`
     *   3. `config('media.variants_disk')`
     *   4. fall back to the media's own original disk
     */
    public function resolveVariantsDisk(?string $override, ?MediaBucket $bucket, string $originalDisk): string
    {
        $configVariantsDisk = config('media.variants_disk');
        $configVariantsDisk = is_string($configVariantsDisk) ? $configVariantsDisk : null;

        $disk = $override
            ?? $bucket?->getVariantsDisk()
            ?? $configVariantsDisk
            ?? $originalDisk;

        $this->ensureDiskExists($disk);

        return $disk;
    }

    public function ensureDiskExists(string $disk): void
    {
        if (! is_array(config('filesystems.disks')) || ! array_key_exists($disk, config('filesystems.disks'))) {
            throw DiskDoesNotExist::named($disk);
        }
    }

    private function configDisk(): string
    {
        $disk = config('media.disk');

        return is_string($disk) ? $disk : 'public';
    }
}
