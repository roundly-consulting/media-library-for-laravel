<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use RoundlyConsulting\MediaLibrary\Actions\AddMediaAction;
use RoundlyConsulting\MediaLibrary\Actions\AttachMediaAction;
use RoundlyConsulting\MediaLibrary\Actions\BindDraftMediaAction;
use RoundlyConsulting\MediaLibrary\Actions\CopyMediaAction;
use RoundlyConsulting\MediaLibrary\Actions\DeleteMediaAction;
use RoundlyConsulting\MediaLibrary\Actions\MoveMediaAction;
use RoundlyConsulting\MediaLibrary\Actions\MoveMediaVariantsAction;
use RoundlyConsulting\MediaLibrary\Actions\PruneDraftsAction;
use RoundlyConsulting\MediaLibrary\Actions\RegenerateVariantsAction;
use RoundlyConsulting\MediaLibrary\Actions\ReplaceMediaAction;
use RoundlyConsulting\MediaLibrary\Buckets\BucketValidationRules;
use RoundlyConsulting\MediaLibrary\Buckets\FileAdderFactory;
use RoundlyConsulting\MediaLibrary\Buckets\PendingFileAdd;
use RoundlyConsulting\MediaLibrary\Buckets\PendingFileAddState;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\AddedFile;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Handles\MediaVariants;
use RoundlyConsulting\MediaLibrary\Handles\ModelMedia;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;
use RoundlyConsulting\MediaLibrary\Testing\MediaLibraryFake;

/**
 * The media library's public API: the root behind the {@see MediaLibrary} facade, and the class
 * to inject when you prefer dependency injection.
 *
 * - Flat `add*()` / `draft()` start a global (owner-less) add.
 * - `for($model)` scopes adds, reads and deletes to one owning model.
 * - Flat verbs (`attach`, `bindDraft`, `move`, `copy`, `replace`, `delete`, `regenerate`, …) act
 *   on the media they are handed; `variants($media)` groups the variant operations.
 *
 * Every mutation — from the facade, a handle, the `InteractsWithMedia` trait or a `Media` model
 * method — funnels through one of the flat verbs here (adds through `store()`), each of which
 * resolves one action from the container. A host's container override therefore applies
 * everywhere, and {@see MediaLibraryFake} sees every call.
 */
class MediaLibraryManager
{
    public function __construct(
        protected readonly Container $container,
        protected readonly FileAdderFactory $files,
        protected readonly BucketValidationRules $validationRules,
    ) {}

    /** Start adding a global media (no owning model) from an upload or a local path. */
    public function add(string|UploadedFile $file): PendingFileAdd
    {
        return $this->pending($this->files->fromFile($file));
    }

    /**
     * Start a draft media add — the stored row gets a draft token and TTL, with no owning model,
     * until `for($model)->bindDraft($token)` binds it. Chain a terminal `->toBucket()` to persist it.
     */
    public function draft(string|UploadedFile $file): PendingFileAdd
    {
        return $this->add($file)->asDraft();
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

    /** The media of one owning model: add, bind drafts, attach, read, clear and delete. */
    public function for(Model $model): ModelMedia
    {
        return new ModelMedia($this, $this->files, $model);
    }

    /** Variant operations for one media: list the definitions, see what is missing, regenerate. */
    public function variants(Media $media): MediaVariants
    {
        return new MediaVariants($this, $media);
    }

    /**
     * Attach an existing media by reference — a new row pointing at the same stored original,
     * zero bytes copied — to a model's bucket, or to a global bucket when `$to` is null.
     */
    public function attach(Media $media, ?Model $to = null, string $bucket = 'default'): Media
    {
        return $this->container->make(AttachMediaAction::class)->execute($media, $to, $bucket);
    }

    /**
     * Bind a previously-uploaded draft (by its token) to a model's bucket. Throws when the token
     * is unknown or already bound, or when the draft's TTL has lapsed.
     */
    public function bindDraft(string $token, Model $to, string $bucket = 'default'): Media
    {
        return $this->container->make(BindDraftMediaAction::class)->execute($to, $token, $bucket);
    }

    /**
     * Relocate a media (original + variants) to another owner/bucket — or to a global bucket when
     * `$to` is null — and optionally onto another disk.
     */
    public function move(Media $media, ?Model $to = null, string $bucket = 'default', ?string $disk = null): Media
    {
        return $this->container->make(MoveMediaAction::class)->execute($media, $to, $bucket, $disk);
    }

    /** Move a media's files onto another disk, keeping its owner and bucket. */
    public function moveToDisk(Media $media, string $disk): Media
    {
        $owner = $media->model;

        return $this->container->make(MoveMediaAction::class)
            ->execute($media, $owner instanceof Model ? $owner : null, $media->bucket_name, $disk);
    }

    /** Move only a media's variant files onto another disk; the original stays put. */
    public function moveVariantsToDisk(Media $media, string $disk): Media
    {
        return $this->container->make(MoveMediaVariantsAction::class)->execute($media, $disk);
    }

    /**
     * Duplicate a media (original + variants) into a new row with a fresh uuid — under another
     * owner/bucket, a global bucket when `$to` is null, and optionally onto another disk.
     */
    public function copy(Media $media, ?Model $to = null, string $bucket = 'default', ?string $disk = null): Media
    {
        return $this->container->make(CopyMediaAction::class)->execute($media, $to, $bucket, $disk);
    }

    /** Replace a media's bytes in place — same id, uuid and URL; metadata and variants are rebuilt. */
    public function replace(Media $media, string|UploadedFile $file): Media
    {
        return $this->container->make(ReplaceMediaAction::class)->execute($media, $file);
    }

    /** Permanently delete a media: its row and its stored files (refcount-guarded for shared originals). */
    public function delete(Media $media): void
    {
        $this->container->make(DeleteMediaAction::class)->execute($media);
    }

    /**
     * Re-render a media's variants from its bucket definitions. Only missing variants by default;
     * `$force` re-renders generated ones too; `$only` narrows the run. Returns the rendered names.
     *
     * @param  list<string>  $only
     * @return list<string>
     */
    public function regenerate(Media $media, array $only = [], bool $force = false): array
    {
        return $this->container->make(RegenerateVariantsAction::class)->execute($media, $only, $force);
    }

    /** Delete every expired, never-bound draft (rows and files). Returns how many were pruned. */
    public function pruneDrafts(): int
    {
        return $this->container->make(PruneDraftsAction::class)->execute();
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

    /**
     * The media in a global bucket, in order.
     *
     * @return Builder<Media>
     */
    public function bucket(string $bucket = 'default'): Builder
    {
        return MediaModel::query()->global()->inBucket($bucket)->ordered();
    }

    /** Any media — global or owned — by its uuid. */
    public function find(string $uuid): ?Media
    {
        return MediaModel::query()->where('uuid', $uuid)->first();
    }

    /** Delete every media in a global bucket (rows and files). Returns how many were deleted. */
    public function clearBucket(string $bucket = 'default'): int
    {
        $media = $this->bucket($bucket)->get();

        foreach ($media as $item) {
            $this->delete($item);
        }

        return $media->count();
    }

    /**
     * Persist a pending add. The terminal of every {@see PendingFileAdd}, global or owned.
     *
     * @internal called by {@see PendingFileAdd::toBucket()} / `toMediaBucket()`
     */
    public function store(PendingFileAddState $state): Media
    {
        return $this->container->make(AddMediaAction::class)->execute($state);
    }

    private function pending(AddedFile $file): PendingFileAdd
    {
        return new PendingFileAdd($this, null, $file);
    }
}
