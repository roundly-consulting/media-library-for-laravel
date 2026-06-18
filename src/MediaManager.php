<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use RoundlyConsulting\MediaLibrary\Buckets\BucketValidationRules;
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
        private readonly BucketValidationRules $validationRules,
    ) {}

    public function add(string|UploadedFile $file): PendingFileAdd
    {
        return $this->fileAdderFactory->fromFile(null, $file);
    }

    /**
     * Start a draft media add — the stored row gets a draft token and TTL, with no owning model,
     * until `attachDraftMedia()` binds it. Chain a terminal `->toBucket()` to persist it.
     */
    public function draft(string|UploadedFile $file): PendingFileAdd
    {
        return $this->fileAdderFactory->fromFile(null, $file)->asDraft();
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

    /**
     * The Laravel validation rules derived from a model's bucket definition (§6.8) — usable
     * directly in a FormRequest. Changing the bucket changes the rules.
     *
     * @param  class-string  $modelClass
     * @return list<string>
     */
    public function rulesFor(string $modelClass, string $bucket = 'default'): array
    {
        return $this->validationRules->forModel($modelClass, $bucket);
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
