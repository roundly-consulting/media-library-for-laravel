<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Concerns;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use RoundlyConsulting\MediaLibrary\Buckets\FileAdderFactory;
use RoundlyConsulting\MediaLibrary\Buckets\MediaBucket;
use RoundlyConsulting\MediaLibrary\Buckets\PendingFileAdd;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Variants\Variant;
use RoundlyConsulting\MediaLibrary\Variants\VariantCollection;
use RoundlyConsulting\MediaLibrary\Variants\VariantRegistrar;

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

    private ?VariantRegistrar $variantRegistrar = null;

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

    /**
     * Host hook for variants declared outside a bucket closure, targeted with
     * `->performOnBuckets()`. The optional media is passed so variants can vary per file.
     */
    public function registerMediaVariants(?Media $media = null): void
    {
        // Host models may override this to declare model-wide variants.
    }

    /** Run the model-level variant hook and return its collected definitions. */
    public function resolveModelMediaVariants(?Media $media = null): VariantCollection
    {
        $registrar = new VariantRegistrar;

        $this->variantRegistrar = $registrar;
        $this->registerMediaVariants($media);
        $this->variantRegistrar = null;

        return $registrar->collection();
    }

    /** Used inside `registerMediaVariants()` to declare a variant: `$this->addMediaVariant('x')`. */
    public function addMediaVariant(string $name): Variant
    {
        $registrar = $this->variantRegistrar ?? new VariantRegistrar;

        return $registrar->add($name);
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

    public function getFirstMediaUrl(string $bucket = 'default', string $variant = ''): string
    {
        $media = $this->getFirstMedia($bucket);

        if ($media !== null) {
            return $media->getUrl($variant);
        }

        return $this->resolveMediaBucket($bucket)?->getFallbackUrl() ?? '';
    }

    /**
     * A temporary URL for the first media in a bucket (presigned or signed-route, §9.1).
     *
     * Returns the bucket's fallback URL (or '') when the bucket is empty. Pass `$expiry` to
     * override the default lifetime from `config('media.temporary_url_default_lifetime')`.
     */
    public function getFirstTemporaryUrl(string $bucket = 'default', string $variant = '', ?DateTimeInterface $expiry = null): string
    {
        $media = $this->getFirstMedia($bucket);

        if ($media !== null) {
            return $media->getTemporaryUrl($expiry ?? $this->defaultTemporaryUrlExpiry(), $variant);
        }

        return $this->resolveMediaBucket($bucket)?->getFallbackUrl() ?? '';
    }

    private function defaultTemporaryUrlExpiry(): DateTimeInterface
    {
        $minutes = config('media.temporary_url_default_lifetime');
        $minutes = is_numeric($minutes) ? (int) $minutes : 5;

        return CarbonImmutable::now()->addMinutes($minutes);
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
