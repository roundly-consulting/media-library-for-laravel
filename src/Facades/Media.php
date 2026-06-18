<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Facades;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\MediaLibrary\Buckets\PendingFileAdd;
use RoundlyConsulting\MediaLibrary\MediaManager;

/**
 * @method static PendingFileAdd add(string|UploadedFile $file)
 * @method static PendingFileAdd addFromUrl(string $url)
 * @method static PendingFileAdd addFromDisk(string $path, ?string $disk = null)
 * @method static PendingFileAdd addFromString(string $contents)
 * @method static PendingFileAdd addFromBase64(string $base64)
 * @method static PendingFileAdd addFromStream(resource $stream)
 * @method static Builder<\RoundlyConsulting\MediaLibrary\Models\Media> bucket(string $bucket = 'default')
 * @method static \RoundlyConsulting\MediaLibrary\Models\Media|null find(string $uuid)
 * @method static void clearBucket(string $bucket = 'default')
 *
 * @see MediaManager
 */
final class Media extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MediaManager::class;
    }
}
