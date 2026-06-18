<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use RoundlyConsulting\MediaLibrary\Buckets\FileAdderFactory;
use RoundlyConsulting\MediaLibrary\Buckets\PendingFileAdd;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * Entry point for global (model-less) media. Backs the {@see Facades\Media}
 * facade and reuses the same {@see PendingFileAdd} builder as model-bound media, with a null owner.
 */
final class MediaManager
{
    public function __construct(
        private readonly FileAdderFactory $fileAdderFactory,
    ) {}

    public function add(string|UploadedFile $file): PendingFileAdd
    {
        return $this->fileAdderFactory->fromFile(null, $file);
    }

    public function addFromUrl(string $url): PendingFileAdd
    {
        return $this->fileAdderFactory->fromUrl(null, $url);
    }

    public function addFromDisk(string $path, ?string $disk = null): PendingFileAdd
    {
        return $this->fileAdderFactory->fromDisk(null, $path, $disk);
    }

    public function addFromString(string $contents): PendingFileAdd
    {
        return $this->fileAdderFactory->fromString(null, $contents);
    }

    public function addFromBase64(string $base64): PendingFileAdd
    {
        return $this->fileAdderFactory->fromBase64(null, $base64);
    }

    /**
     * @param  resource  $stream
     */
    public function addFromStream($stream): PendingFileAdd
    {
        return $this->fileAdderFactory->fromStream(null, $stream);
    }

    /** @return Builder<Media> */
    public function bucket(string $bucket = 'default'): Builder
    {
        return $this->query()->global()->inBucket($bucket)->ordered();
    }

    public function find(string $uuid): ?Media
    {
        return $this->query()->where('uuid', $uuid)->first();
    }

    public function clearBucket(string $bucket = 'default'): void
    {
        $this->query()->global()->inBucket($bucket)->get()
            ->each(fn (Media $media): mixed => $media->forceDelete());
    }

    /** @return Builder<Media> */
    private function query(): Builder
    {
        /** @var class-string<Media> $modelClass */
        $modelClass = config('media.media_model');

        return $modelClass::query();
    }
}
