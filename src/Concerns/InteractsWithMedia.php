<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use RoundlyConsulting\MediaLibrary\Buckets\FileAdderFactory;
use RoundlyConsulting\MediaLibrary\Buckets\MediaBucket;
use RoundlyConsulting\MediaLibrary\Buckets\PendingFileAdd;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * Opt-in media behaviour for Eloquent models: the `media()` relation, fluent file adders,
 * bucket declarations, and convenience readers.
 *
 * @mixin Model
 */
trait InteractsWithMedia
{
    /** @var array<string, MediaBucket> */
    private array $mediaBuckets = [];

    private bool $mediaBucketsRegistered = false;

    /** @return MorphMany<Media, $this> */
    public function media(): MorphMany
    {
        /** @var class-string<Media> $modelClass */
        $modelClass = config('media.media_model');

        return $this->morphMany($modelClass, 'model')->ordered();
    }

    public function registerMediaBuckets(): void
    {
        // Host models override this to declare their buckets.
    }

    public function addMediaBucket(string $name): MediaBucket
    {
        $bucket = new MediaBucket($name);

        $this->mediaBuckets[$name] = $bucket;

        return $bucket;
    }

    public function resolveMediaBucket(string $name): ?MediaBucket
    {
        if (! $this->mediaBucketsRegistered) {
            $this->registerMediaBuckets();
            $this->mediaBucketsRegistered = true;
        }

        return $this->mediaBuckets[$name] ?? null;
    }

    public function addMedia(string|UploadedFile $file): PendingFileAdd
    {
        return $this->fileAdderFactory()->fromFile($this, $file);
    }

    public function addMediaFromRequest(string $key): PendingFileAdd
    {
        /** @var UploadedFile $file */
        $file = request()->file($key);

        return $this->fileAdderFactory()->fromFile($this, $file);
    }

    /**
     * @param  list<string>  $keys
     * @return list<PendingFileAdd>
     */
    public function addMultipleMediaFromRequest(array $keys): array
    {
        $adders = [];

        foreach ($keys as $key) {
            $file = request()->file($key);

            if ($file instanceof UploadedFile) {
                $adders[] = $this->fileAdderFactory()->fromFile($this, $file);
            }
        }

        return $adders;
    }

    public function addMediaFromUrl(string $url): PendingFileAdd
    {
        return $this->fileAdderFactory()->fromUrl($this, $url);
    }

    public function addMediaFromDisk(string $path, ?string $disk = null): PendingFileAdd
    {
        return $this->fileAdderFactory()->fromDisk($this, $path, $disk);
    }

    public function addMediaFromString(string $contents): PendingFileAdd
    {
        return $this->fileAdderFactory()->fromString($this, $contents);
    }

    public function addMediaFromBase64(string $base64): PendingFileAdd
    {
        return $this->fileAdderFactory()->fromBase64($this, $base64);
    }

    /**
     * @param  resource  $stream
     */
    public function addMediaFromStream($stream): PendingFileAdd
    {
        return $this->fileAdderFactory()->fromStream($this, $stream);
    }

    /** @return Collection<int, Media> */
    public function getMedia(string $bucket = 'default'): Collection
    {
        return $this->media()->where('bucket_name', $bucket)->get();
    }

    public function getFirstMedia(string $bucket = 'default'): ?Media
    {
        return $this->media()->where('bucket_name', $bucket)->first();
    }

    public function getFirstMediaUrl(string $bucket = 'default'): string
    {
        $media = $this->getFirstMedia($bucket);

        if ($media !== null) {
            return $media->getUrl();
        }

        return $this->resolveMediaBucket($bucket)?->getFallbackUrl() ?? '';
    }

    public function hasMedia(string $bucket = 'default'): bool
    {
        return $this->media()->where('bucket_name', $bucket)->exists();
    }

    public function clearMediaBucket(string $bucket = 'default'): void
    {
        $this->getMedia($bucket)->each(fn (Media $media): mixed => $media->forceDelete());
    }

    private function fileAdderFactory(): FileAdderFactory
    {
        return app(FileAdderFactory::class);
    }
}
