<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Handles;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use RoundlyConsulting\MediaLibrary\Buckets\FileAdderFactory;
use RoundlyConsulting\MediaLibrary\Buckets\PendingFileAdd;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\AddedFile;
use RoundlyConsulting\MediaLibrary\Exceptions\FileDoesNotExist;
use RoundlyConsulting\MediaLibrary\Exceptions\MediaDoesNotBelongToModel;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\MediaConfig;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;

/**
 * The media of one owning model — `MediaLibrary::for($post)`.
 *
 * Adds are owner-scoped (the bucket's disk, visibility, acceptance and single-file rules apply),
 * reads only ever see this owner's rows, and `delete()` refuses media owned by anyone else.
 * Mutations delegate to the flat {@see MediaLibraryManager} verbs, so a fake records them.
 */
final readonly class ModelMedia
{
    public function __construct(
        private MediaLibraryManager $manager,
        private FileAdderFactory $files,
        private Model $model,
    ) {}

    public function add(string|UploadedFile $file): PendingFileAdd
    {
        return $this->pending($this->files->fromFile($file));
    }

    /** Start an add from the current request's uploaded file under `$key`. */
    public function addFromRequest(string $key): PendingFileAdd
    {
        $file = request()->file($key);

        if (! $file instanceof UploadedFile) {
            throw FileDoesNotExist::inRequest($key);
        }

        return $this->add($file);
    }

    public function addFromUrl(string $url): PendingFileAdd
    {
        return $this->pending($this->files->fromUrl($url));
    }

    public function addFromDisk(string $path, ?string $disk = null): PendingFileAdd
    {
        return $this->pending($this->files->fromDisk($path, $disk));
    }

    public function addFromString(string $contents): PendingFileAdd
    {
        return $this->pending($this->files->fromString($contents));
    }

    public function addFromBase64(string $base64): PendingFileAdd
    {
        return $this->pending($this->files->fromBase64($base64));
    }

    /**
     * @param  resource  $stream
     */
    public function addFromStream($stream): PendingFileAdd
    {
        return $this->pending($this->files->fromStream($stream));
    }

    /** Bind a previously-uploaded draft (by its token) to this model's bucket. */
    public function bindDraft(string $token, string $bucket = 'default'): Media
    {
        return $this->manager->bindDraft($token, $this->model, $bucket);
    }

    /** Attach an existing media to this model's bucket by reference (zero bytes copied). */
    public function attach(Media $media, string $bucket = 'default'): Media
    {
        return $this->manager->attach($media, $this->model, $bucket);
    }

    /** @return Collection<int, Media> */
    public function get(string $bucket = 'default'): Collection
    {
        return $this->query($bucket)->get();
    }

    public function first(string $bucket = 'default'): ?Media
    {
        return $this->query($bucket)->first();
    }

    public function has(string $bucket = 'default'): bool
    {
        return $this->query($bucket)->exists();
    }

    /** One of this model's media by uuid — null when the uuid belongs to another owner. */
    public function find(string $uuid): ?Media
    {
        return $this->owned()->where('uuid', $uuid)->first();
    }

    /** The first media's URL in a bucket, else the bucket's fallback URL, else ''. */
    public function url(string $bucket = 'default', string $variant = ''): string
    {
        $media = $this->first($bucket);

        return $media !== null ? $media->getUrl($variant) : $this->fallbackUrl($bucket);
    }

    /**
     * A temporary URL (presigned or signed-route) for the first media in a bucket, else the
     * bucket's fallback URL, else ''. `$expiry` defaults to `media.temporary_url_default_lifetime`.
     */
    public function temporaryUrl(string $bucket = 'default', string $variant = '', ?DateTimeInterface $expiry = null): string
    {
        $media = $this->first($bucket);

        if ($media === null) {
            return $this->fallbackUrl($bucket);
        }

        return $media->getTemporaryUrl($expiry ?? $this->defaultTemporaryUrlExpiry(), $variant);
    }

    /** Delete every media in one of this model's buckets. Returns how many were deleted. */
    public function clear(string $bucket = 'default'): int
    {
        $media = $this->get($bucket);

        foreach ($media as $item) {
            $this->manager->delete($item);
        }

        return $media->count();
    }

    /**
     * Delete one of this model's media (row and files).
     *
     * @throws MediaDoesNotBelongToModel when the media is global or owned by another model
     */
    public function delete(Media $media): void
    {
        if (! $this->owns($media)) {
            throw MediaDoesNotBelongToModel::make($media->uuid, $this->model);
        }

        $this->manager->delete($media);
    }

    private function owns(Media $media): bool
    {
        return $media->model_type === $this->model->getMorphClass()
            && $media->model_id !== null
            && (string) $media->model_id === (string) $this->model->getKey();
    }

    private function pending(AddedFile $file): PendingFileAdd
    {
        return new PendingFileAdd($this->manager, $this->model, $file);
    }

    /** @return Builder<Media> */
    private function owned(): Builder
    {
        return MediaModel::query()->forModel($this->model);
    }

    /** @return Builder<Media> */
    private function query(string $bucket): Builder
    {
        return $this->owned()->inBucket($bucket)->ordered();
    }

    private function fallbackUrl(string $bucket): string
    {
        if (! $this->model instanceof HasMedia) {
            return '';
        }

        return $this->model->resolveMediaBucket($bucket)?->getFallbackUrl() ?? '';
    }

    private function defaultTemporaryUrlExpiry(): DateTimeInterface
    {
        return CarbonImmutable::now()->addMinutes(MediaConfig::temporaryUrlLifetime());
    }
}
