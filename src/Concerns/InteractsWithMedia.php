<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Concerns;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use RoundlyConsulting\MediaLibrary\Buckets\MediaBucket;
use RoundlyConsulting\MediaLibrary\Buckets\PendingFileAdd;
use RoundlyConsulting\MediaLibrary\Handles\ModelMedia;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Relations\MediaMorphMany;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;
use RoundlyConsulting\MediaLibrary\Variants\Variant;
use RoundlyConsulting\MediaLibrary\Variants\VariantCollection;
use RoundlyConsulting\MediaLibrary\Variants\VariantRegistrar;

/**
 * Opt-in media behaviour for Eloquent models: the `media()` relation, fluent file adders,
 * bucket declarations, and convenience readers.
 *
 * The adders, readers and mutators are sugar over `MediaLibrary::for($this)` — they go through
 * the manager, so a host's container overrides and `MediaLibrary::fake()` see them.
 *
 * @mixin Model
 */
trait InteractsWithMedia
{
    /** @var array<string, MediaBucket> */
    private array $mediaBuckets = [];

    private bool $mediaBucketsRegistered = false;

    private ?VariantRegistrar $variantRegistrar = null;

    /**
     * The owner's media, newest ordering applied.
     *
     * This builds `MediaMorphMany` rather than calling `$this->morphMany()` so the string-morph
     * key handling stays scoped to this relation: overriding the host's `newMorphMany()` would
     * silently change every other morphMany it declares. See `MediaMorphMany` for why Eloquent's
     * integer-key eager-load optimisation is wrong for `media.model_id`.
     *
     * @return MorphMany<Media, $this>
     */
    public function media(): MorphMany
    {
        $instance = $this->newRelatedInstance(MediaModel::class());

        // The `model` morph's columns, as `getMorphs('model', null, null)` would derive them
        // (`[$type ?: $name.'_type', $id ?: $name.'_id']`) and as the migration declares them.
        /** @var MediaMorphMany<Media, $this> $relation */
        $relation = new MediaMorphMany(
            $instance->newQuery(),
            $this,
            $instance->qualifyColumn('model_type'),
            $instance->qualifyColumn('model_id'),
            $this->getKeyName(),
        );

        return $relation->ordered();
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
        return $this->mediaLibrary()->add($file);
    }

    public function addMediaFromRequest(string $key): PendingFileAdd
    {
        return $this->mediaLibrary()->addFromRequest($key);
    }

    /**
     * One pending add per key that carries an uploaded file; keys without one are skipped.
     *
     * @param  list<string>  $keys
     * @return list<PendingFileAdd>
     */
    public function addMultipleMediaFromRequest(array $keys): array
    {
        $adders = [];

        foreach ($keys as $key) {
            if (request()->file($key) instanceof UploadedFile) {
                $adders[] = $this->mediaLibrary()->addFromRequest($key);
            }
        }

        return $adders;
    }

    public function addMediaFromUrl(string $url): PendingFileAdd
    {
        return $this->mediaLibrary()->addFromUrl($url);
    }

    public function addMediaFromDisk(string $path, ?string $disk = null): PendingFileAdd
    {
        return $this->mediaLibrary()->addFromDisk($path, $disk);
    }

    public function addMediaFromString(string $contents): PendingFileAdd
    {
        return $this->mediaLibrary()->addFromString($contents);
    }

    public function addMediaFromBase64(string $base64): PendingFileAdd
    {
        return $this->mediaLibrary()->addFromBase64($base64);
    }

    /**
     * @param  resource  $stream
     */
    public function addMediaFromStream($stream): PendingFileAdd
    {
        return $this->mediaLibrary()->addFromStream($stream);
    }

    /** @return Collection<int, Media> */
    public function getMedia(string $bucket = 'default'): Collection
    {
        return $this->mediaLibrary()->get($bucket);
    }

    public function getFirstMedia(string $bucket = 'default'): ?Media
    {
        return $this->mediaLibrary()->first($bucket);
    }

    public function getFirstMediaUrl(string $bucket = 'default', string $variant = ''): string
    {
        return $this->mediaLibrary()->url($bucket, $variant);
    }

    /**
     * A temporary URL for the first media in a bucket (presigned or signed-route, §9.1).
     *
     * Returns the bucket's fallback URL (or '') when the bucket is empty. Pass `$expiry` to
     * override the default lifetime from `config('media.temporary_url_default_lifetime')`.
     */
    public function getFirstTemporaryUrl(string $bucket = 'default', string $variant = '', ?DateTimeInterface $expiry = null): string
    {
        return $this->mediaLibrary()->temporaryUrl($bucket, $variant, $expiry);
    }

    public function hasMedia(string $bucket = 'default'): bool
    {
        return $this->mediaLibrary()->has($bucket);
    }

    /** Delete every media (rows and files) in one of this model's buckets. */
    public function clearMediaBucket(string $bucket = 'default'): void
    {
        $this->mediaLibrary()->clear($bucket);
    }

    /**
     * Bind a previously-uploaded draft media (by its token) to this model's bucket.
     *
     * Throws when the token is unknown (already bound / never issued) or its TTL has expired.
     */
    public function attachDraftMedia(string $token, string $bucket = 'default'): Media
    {
        return $this->mediaLibrary()->bindDraft($token, $bucket);
    }

    /**
     * Attach an existing (often global) media to this model by reference — a new row is created
     * that reuses the same stored original file with zero bytes copied, then the target bucket's
     * variants are generated for it.
     */
    public function attachMedia(Media $media, string $bucket = 'default'): Media
    {
        return $this->mediaLibrary()->attach($media, $bucket);
    }

    /** This model's handle on the (possibly faked) manager — every call above goes through it. */
    private function mediaLibrary(): ModelMedia
    {
        return app(MediaLibraryManager::class)->for($this);
    }
}
