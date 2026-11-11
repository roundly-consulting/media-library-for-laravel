<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use RoundlyConsulting\MediaLibrary\Actions\AbortDirectUploadAction;
use RoundlyConsulting\MediaLibrary\Actions\AddMediaAction;
use RoundlyConsulting\MediaLibrary\Actions\AdoptDirectUploadAction;
use RoundlyConsulting\MediaLibrary\Actions\AttachMediaAction;
use RoundlyConsulting\MediaLibrary\Actions\BindDraftMediaAction;
use RoundlyConsulting\MediaLibrary\Actions\CompleteMultipartUploadAction;
use RoundlyConsulting\MediaLibrary\Actions\CopyMediaAction;
use RoundlyConsulting\MediaLibrary\Actions\DeleteMediaAction;
use RoundlyConsulting\MediaLibrary\Actions\IssueDirectUploadAction;
use RoundlyConsulting\MediaLibrary\Actions\MeasureStorageUsageAction;
use RoundlyConsulting\MediaLibrary\Actions\MoveMediaAction;
use RoundlyConsulting\MediaLibrary\Actions\MoveMediaVariantsAction;
use RoundlyConsulting\MediaLibrary\Actions\PruneDirectUploadsAction;
use RoundlyConsulting\MediaLibrary\Actions\PruneDraftsAction;
use RoundlyConsulting\MediaLibrary\Actions\RegenerateVariantsAction;
use RoundlyConsulting\MediaLibrary\Actions\ReplaceMediaAction;
use RoundlyConsulting\MediaLibrary\Actions\SignUploadPartsAction;
use RoundlyConsulting\MediaLibrary\Buckets\BucketValidationRules;
use RoundlyConsulting\MediaLibrary\Buckets\FileAdderFactory;
use RoundlyConsulting\MediaLibrary\Buckets\PendingFileAdd;
use RoundlyConsulting\MediaLibrary\Buckets\PendingFileAddState;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\AddedFile;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\DirectUpload;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\StorageUsage;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\UploadPart;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadTargetMismatch;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Handles\MediaVariants;
use RoundlyConsulting\MediaLibrary\Handles\ModelMedia;
use RoundlyConsulting\MediaLibrary\Handles\RemoteUrls;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;
use RoundlyConsulting\MediaLibrary\Testing\MediaLibraryFake;
use RoundlyConsulting\MediaLibrary\Uploads\DirectUploadState;
use RoundlyConsulting\MediaLibrary\Uploads\PendingDirectUpload;
use SensitiveParameter;

/**
 * The media library's public API: the root behind the {@see MediaLibrary} facade, and the class
 * to inject when you prefer dependency injection.
 *
 * - Flat `add*()` / `draft()` start a global (owner-less) add.
 * - `for($model)` scopes adds, reads and deletes to one owning model.
 * - Flat verbs (`attach`, `bindDraft`, `move`, `copy`, `replace`, `delete`, `regenerate`, …) act
 *   on the media they are handed; `variants($media)` groups the variant operations.
 * - `remote()` fetches and vets user-supplied URLs with the guard `addFromUrl()` uses.
 * - `usage()` measures the bytes media store, counting a shared original once.
 * - `upload()` signs a direct upload to storage; `adoptUpload()` (or `completeUpload()` for a
 *   multipart one) turns it into media without the bytes passing through the application.
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

    /**
     * Start a direct upload of a global media: the browser sends the file straight to storage on
     * a signed URL, and `adoptUpload()` turns it into media. `$size` is the size the browser
     * reports, checked against the bucket's cap before anything is signed.
     */
    public function upload(string $fileName, int $size): PendingDirectUpload
    {
        return new PendingDirectUpload($this, null, $fileName, $size);
    }

    /**
     * Sign a pending direct upload and record its ticket.
     *
     * @internal called by {@see PendingDirectUpload::toMediaBucket()} / `toBucket()`
     */
    public function issueUpload(DirectUploadState $state): DirectUpload
    {
        return $this->container->make(IssueDirectUploadAction::class)->execute($state);
    }

    /**
     * Turn a direct upload into media — inspected and moved inside storage, under the bucket's
     * current rules. Adopting an adopted ticket again returns the same media. A ticket issued
     * `forActor()` needs that actor.
     *
     * Name the owner and bucket the route serves (`expectedOwner: $post, expectedBucket: 'cover'`)
     * and a token issued for another owner or bucket throws {@see DirectUploadTargetMismatch}
     * without being spent. Left out, any owner's ticket is adopted, as before.
     */
    public function adoptUpload(
        #[SensitiveParameter] string $token,
        ?Model $actor = null,
        ?Model $expectedOwner = null,
        ?string $expectedBucket = null,
    ): Media {
        return $this->container->make(AdoptDirectUploadAction::class)
            ->execute($token, $actor, expectedOwner: $expectedOwner, expectedBucket: $expectedBucket);
    }

    /**
     * Sign parts of a multipart direct upload (in batches; parts uploaded so far are checked
     * against the cap first).
     *
     * @param  list<int>  $parts
     * @return list<UploadPart>
     */
    public function signUploadParts(#[SensitiveParameter] string $token, array $parts): array
    {
        return $this->container->make(SignUploadPartsAction::class)->execute($token, $parts);
    }

    /**
     * Complete a multipart direct upload with the ETags the browser received, then adopt it.
     * `expectedOwner` / `expectedBucket` work as for `adoptUpload()`, checked before completing.
     *
     * @param  array<int, string>  $parts  part number => ETag
     */
    public function completeUpload(
        #[SensitiveParameter] string $token,
        array $parts,
        ?Model $actor = null,
        ?Model $expectedOwner = null,
        ?string $expectedBucket = null,
    ): Media {
        return $this->container->make(CompleteMultipartUploadAction::class)
            ->execute($token, $parts, $actor, $expectedOwner, $expectedBucket);
    }

    /** Cancel a pending direct upload and remove what it uploaded. */
    public function abortUpload(#[SensitiveParameter] string $token): void
    {
        $this->container->make(AbortDirectUploadAction::class)->execute($token);
    }

    /** Expire unadopted direct uploads past their grace and remove leftovers. Returns how many. */
    public function pruneUploads(): int
    {
        return $this->container->make(PruneDirectUploadsAction::class)->execute();
    }

    /**
     * Adopt a file already in storage as a global media — moved onto the media's path inside
     * storage, never copied through the application. Trusted input only: the path must be under
     * the staging prefix or an adoptable prefix.
     */
    public function adoptFromDisk(string $path, ?string $disk = null): PendingFileAdd
    {
        return $this->pending($this->files->adoptFromDisk($path, $disk));
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
     * Guarded server-side fetches of user-supplied URLs: `fetch()`, `vet()`, `isPrivateAddress()`.
     * Nothing is stored, so the fake keeps this real (`Http::fake()` answers it).
     */
    public function remote(): RemoteUrls
    {
        return new RemoteUrls($this->container);
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
     * Bind a previously-uploaded draft (by its token) to a model's bucket — the bucket's acceptance
     * rules, disk/visibility, single-file rule and variants apply. Throws when the token is unknown
     * or already bound, when the draft's TTL has lapsed, or when the bucket refuses the file.
     */
    public function bindDraft(#[SensitiveParameter] string $token, Model $to, string $bucket = 'default'): Media
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

    /**
     * Move a media's files onto another disk, keeping its owner and bucket exactly as stored —
     * a soft-deleted or missing owner included — and its variants as they are.
     */
    public function moveToDisk(Media $media, string $disk): Media
    {
        return $this->container->make(MoveMediaAction::class)
            ->execute($media, null, $media->bucket_name, $disk, keepOwner: true);
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

    /**
     * Replace a media's bytes — same id and uuid, and the same URL unless the old file is shared
     * with other media (it is then left untouched for them); metadata and variants are rebuilt.
     */
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
     * The media in a global bucket, in order. Unbound drafts are not global media and never appear.
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

    /**
     * The bytes stored for the media `$query` selects: each physical original once, however many
     * rows share it (dedup, `attach()`, a same-disk `copy()`), plus every recorded variant size.
     * Without a query: every media row, soft-deleted ones included — their files are still stored.
     *
     * @param  Builder<Media>|null  $query
     */
    public function usage(?Builder $query = null): StorageUsage
    {
        return $this->container->make(MeasureStorageUsageAction::class)
            ->execute($query ?? MediaModel::query()->withTrashed());
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
