<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RoundlyConsulting\MediaLibrary\Buckets\BucketGuard;
use RoundlyConsulting\MediaLibrary\Buckets\MediaBucket;
use RoundlyConsulting\MediaLibrary\Buckets\PendingFileAddState;
use RoundlyConsulting\MediaLibrary\Contracts\FileNamer;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\MediaLibrary\Contracts\ImageDriver;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\StoredObject;
use RoundlyConsulting\MediaLibrary\Events\MediaHasBeenAdded;
use RoundlyConsulting\MediaLibrary\Exceptions\FileCannotBeWritten;
use RoundlyConsulting\MediaLibrary\Exceptions\FileDoesNotExist;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Placeholders\PlaceholderGenerator;
use RoundlyConsulting\MediaLibrary\Support\Checksum;
use RoundlyConsulting\MediaLibrary\Support\DiskResolver;
use RoundlyConsulting\MediaLibrary\Support\ExifOrientation;
use RoundlyConsulting\MediaLibrary\Support\FileNames;
use RoundlyConsulting\MediaLibrary\Support\ImageDecodeGuard;
use RoundlyConsulting\MediaLibrary\Support\MediaConfig;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;
use RoundlyConsulting\MediaLibrary\Support\ResponseHeaders;
use RoundlyConsulting\MediaLibrary\Support\StoredFiles;
use RoundlyConsulting\MediaLibrary\Uploads\StoredObjects;
use RoundlyConsulting\MediaLibrary\Variants\VariantResolver;
use RoundlyConsulting\PackageToolkit\Support\Config;
use Throwable;

/**
 * Persists a normalized source file as a {@see Media} row and writes its bytes through
 * Laravel's filesystem, honouring the bucket's storage/visibility/acceptance rules.
 *
 * The bucket's mime allowlist, size cap and image dimensions are checked before anything is
 * written; a `singleFile()` bucket's previous media is removed only once the new one is stored.
 * Should a host's transaction roll the new row back, the files this add wrote are deleted again.
 *
 * A source that is already in storage (an adopted direct upload, `adoptFromDisk()`) is never
 * written: it was inspected where it lies, and it is moved inside storage onto the media's path —
 * or kept where it is for an in-place upload — and moved back should the row roll back.
 */
final class AddMediaAction
{
    public function __construct(
        private readonly DiskResolver $diskResolver,
        private readonly FileNamer $fileNamer,
        private readonly DispatchVariantsAction $dispatchVariants,
        private readonly VariantResolver $variantResolver,
        private readonly Checksum $checksum,
        private readonly PlaceholderGenerator $placeholders,
        private readonly BucketGuard $guard,
        private readonly StoredFiles $files,
        private readonly StoredObjects $objects,
    ) {}

    public function execute(PendingFileAddState $state): Media
    {
        // A temporary source (from a string, a stream, a URL, base64) is discarded however the
        // add ends — a refused or failed add would otherwise leave it in the temp dir for good.
        try {
            return $this->add($state);
        } finally {
            $this->discardSource($state);
        }
    }

    private function add(PendingFileAddState $state): Media
    {
        // A draft only borrows its owner's bucket definition; everything else is stored against it.
        if (! $state->draft) {
            $this->guard->ensureSavedOwner($state->owner);
        }

        $bucket = $this->guard->bucketFor($state->owner, $state->bucket);

        $object = $state->file->object;

        // What a viewer sees: a phone photo stored sideways with an EXIF turn records upright.
        $dimensions = match (true) {
            $object !== null => $object->width !== null && $object->height !== null ? [$object->width, $object->height] : null,
            str_starts_with((string) $state->file->mimeType, 'image/') => ExifOrientation::displayDimensions($state->file->path),
            default => null,
        };

        $this->guard->ensureAccepts(
            $bucket,
            $state->bucket,
            $state->file->mimeType,
            $state->file->size,
            $dimensions[0] ?? null,
            $dimensions[1] ?? null,
        );

        $disk = $this->diskResolver->resolveOriginalDisk($state->diskOverride, $bucket);
        $variantsDisk = $this->diskResolver->resolveVariantsDisk($state->variantsDiskOverride, $bucket, $disk);

        $visibility = $this->resolveVisibility($state->visibility, $bucket);

        $media = $this->makeMedia($state, $disk, $variantsDisk, $visibility);

        $checksum = $object !== null ? $object->checksum : $this->checksum->forLocalFile($state->file->path);
        $media->checksum = $checksum;

        if ($dimensions !== null) {
            [$media->width, $media->height] = $dimensions;
        }

        $this->capturePlaceholders($media, $state);

        $shared = $this->saveDeduplicated($media, $disk, $visibility, $checksum);

        if (! $shared) {
            $this->placeOriginal($media, $state, $disk, $visibility);

            try {
                $media->save();
            } catch (Throwable $exception) {
                // No row will ever point at the file just written (or moved) for it.
                $this->undoPlacement($media, $object, $disk);

                throw $exception;
            }
        }

        // The shared file can vanish between the check and the save (its last other referrer
        // force-deleted meanwhile): then this row gets its own copy after all.
        if ($shared && ! Storage::disk($disk)->exists($media->getPath())) {
            $this->placeOriginal($media, $state, $disk, $visibility);
            $media->save();
            $shared = false;
        }

        // A host's transaction can still roll the row back. A shared original stays: its other
        // referrer still points at it. A moved upload goes back where it came from.
        // One adopted in place stays put either way: its ticket can be adopted again.
        if (! $shared && $object !== null) {
            if ($this->wasMoved($media, $object, $disk)) {
                $this->objects->moveBackAfterRollBack($media, $object->disk, $object->path, $disk, $media->getPath(), $object->size, $visibility);
            }
        } elseif (! $shared) {
            $this->files->deleteAfterRollBack($media, $disk, $media->getPath());
        }

        // Identical content was already stored: the adopted upload is not needed once this commits.
        if ($shared && $object !== null) {
            $this->files->deleteAfterCommit($media, $object->disk, $object->path);
        }

        if (! $state->draft && $state->owner instanceof Model) {
            $this->guard->enforceSingleFile($bucket, $state->owner, $state->bucket, $media);
        }

        event(new MediaHasBeenAdded($media));

        $this->generateVariantsFor($media, $bucket, $state);

        // Only what was rendered just now: inside a transaction, queued variants are dispatched
        // once it commits, so a rollback never leaves one behind.
        foreach (array_keys($media->generatedVariants()) as $variant) {
            $this->files->deleteAfterRollBack($media, $media->diskFor($variant), $media->getPath($variant));
        }

        return $media;
    }

    /**
     * For image media, compute the LQIP placeholders from the local source. Any image-decoding
     * failure is swallowed so a quirky file never blocks the upload itself.
     */
    private function capturePlaceholders(Media $media, PendingFileAddState $state): void
    {
        // Only raster images are ever decoded (an SVG never is); an adopted upload too large to
        // keep a local copy of gets its placeholders from the variants pass instead.
        if (! ImageDecodeGuard::decodes($media->mime_type) || ! is_file($state->file->path)) {
            return;
        }

        try {
            $placeholders = $this->placeholders->forLocalImage($state->file->path, app(ImageDriver::class));

            if ($placeholders !== []) {
                $media->placeholders = $placeholders;
            }
        } catch (Throwable) {
            // A decode/driver failure must not fail the add — the media simply has no placeholder.
        }
    }

    private function generateVariantsFor(Media $media, ?MediaBucket $bucket, PendingFileAddState $state): void
    {
        if ($bucket === null || ! $state->owner instanceof HasMedia) {
            return;
        }

        $this->dispatchVariants->execute(
            $media,
            $this->variantResolver->forOwnerBucket($state->owner, $state->bucket, $media),
            $state->queue,
        );
    }

    private function makeMedia(
        PendingFileAddState $state,
        string $disk,
        string $variantsDisk,
        string $visibility,
    ): Media {
        $media = MediaModel::new();

        $media->uuid = $state->uuid ?? (string) Str::uuid();
        $media->bucket_name = $state->bucket;
        $media->name = FileNames::displayName($state->name ?? $state->file->name);
        $media->file_name = $this->fileName($state);
        $media->mime_type = $state->file->mimeType;
        $media->extension = FileNames::extensionOf($media->file_name);
        $media->disk = $disk;
        $media->variants_disk = $variantsDisk === $disk ? null : $variantsDisk;
        $media->size = $state->file->size;
        $media->visibility = $visibility;
        $media->custom_properties = $state->customProperties;
        $media->generated_variants = [];
        $media->order_column = $this->nextOrderColumn($state->owner, $state->bucket);

        if ($state->draft) {
            // A draft stays unbound: no owning model until attachDraftMedia() binds it on save.
            $media->draft_token = (string) Str::uuid();
            $media->draft_expires_at = CarbonImmutable::now()->addMinutes($this->draftTtl());
        } elseif ($state->owner instanceof Model) {
            $media->model_type = $state->owner->getMorphClass();
            $media->model_id = $state->owner->getKey();
        }

        return $media;
    }

    /**
     * The stored name. A `usingFileName()` override is as untrusted as a client's upload name, so
     * it is reduced to one safe path segment whose extension cannot lie about the sniffed bytes —
     * and so is whatever a host's {@see FileNamer} makes of it.
     */
    private function fileName(PendingFileAddState $state): string
    {
        $requested = $state->fileName !== null
            ? FileNames::conform(FileNames::sanitize($state->fileName), $state->file->mimeType)
            : $state->file->fileName;

        return FileNames::sanitize($this->fileNamer->originalFileName($requested));
    }

    /**
     * Save the row onto an identical stored original when one exists for the same
     * `(disk, visibility, checksum)` inside this row's dedup scope: it points its `path` at that
     * file and writes nothing. Saved under the original's row lock, so a concurrent delete cannot
     * remove the file before this row commits, and the scope is checked again under that lock.
     * Returns whether it deduplicated (and saved).
     */
    private function saveDeduplicated(Media $media, string $disk, string $visibility, ?string $checksum): bool
    {
        $canonical = $this->dedupCanonical($media, $disk, $visibility, $checksum);

        return $canonical !== null && $this->files->shareOriginalOf(
            $canonical,
            static function (string $path) use ($media): void {
                $media->path = $path;
                $media->save();
            },
            fn (Media $locked): bool => $this->checksum->stillInScope($locked, $media),
        );
    }

    /**
     * Give the row its own original: write the local source, or place a stored object — kept
     * where it is when it was uploaded in place, else moved inside storage to a free path.
     */
    private function placeOriginal(Media $media, PendingFileAddState $state, string $disk, string $visibility): void
    {
        $object = $state->file->object;

        if ($object === null) {
            $this->writeOriginal($media, $state, $disk, $visibility);

            return;
        }

        if ($object->inPlace && $object->disk === $disk && ! $this->files->isReferencedByOthers($disk, $object->path, $media->getKey())) {
            $media->path = $object->path;

            return;
        }

        $target = $this->files->freePath($media, $disk);
        $this->objects->move($object->disk, $object->path, $disk, $target, $object->size, $media->mime_type, ResponseHeaders::storedDisposition($media), $visibility);
        $media->path = $target;
    }

    private function wasMoved(Media $media, StoredObject $object, string $disk): bool
    {
        return $object->disk !== $disk || $object->path !== $media->getPath();
    }

    /** After a failed save: delete a written file, put a moved object back, leave an in-place one. */
    private function undoPlacement(Media $media, ?StoredObject $object, string $disk): void
    {
        if ($object === null) {
            Storage::disk($disk)->delete($media->getPath());

            return;
        }

        if ($this->wasMoved($media, $object, $disk)) {
            $this->objects->move($disk, $media->getPath(), $object->disk, $object->path, $object->size, null, 'attachment', 'private');
        }
    }

    /**
     * Write the source to a path of this row's own.
     *
     * @throws FileDoesNotExist when the source can no longer be read
     * @throws FileCannotBeWritten when the disk refuses the write
     */
    private function writeOriginal(Media $media, PendingFileAddState $state, string $disk, string $visibility): void
    {
        $target = $this->files->freePath($media, $disk);
        $media->path = $target;

        $stream = @fopen($state->file->path, 'rb');

        if ($stream === false) {
            throw FileDoesNotExist::forPath($state->file->path);
        }

        try {
            $written = Storage::disk($disk)->put($target, $stream, ['visibility' => $visibility]);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if (! $written) {
            throw FileCannotBeWritten::toDisk($target, $disk);
        }
    }

    /** An identical stored original to share — only one whose file is really still there. */
    private function dedupCanonical(Media $media, string $disk, string $visibility, ?string $checksum): ?Media
    {
        if ($checksum === null || ! Config::boolean('media.deduplicate', true)) {
            return null;
        }

        $canonical = $this->checksum->canonicalFor($disk, $visibility, $checksum, for: $media);

        return $canonical !== null && Storage::disk($disk)->exists($canonical->getPath()) ? $canonical : null;
    }

    /** Remove the package's own temporary copy of the source; a caller's local path is never touched. */
    private function discardSource(PendingFileAddState $state): void
    {
        if ($state->file->isTemporary && is_file($state->file->path)) {
            @unlink($state->file->path);
        }
    }

    private function resolveVisibility(?string $override, ?MediaBucket $bucket): string
    {
        return $override ?? $bucket?->getVisibility() ?? MediaConfig::defaultVisibility();
    }

    private function nextOrderColumn(HasMedia|Model|null $owner, string $bucket): int
    {
        $query = MediaModel::query()->where('bucket_name', $bucket);

        if ($owner instanceof Model) {
            $query->where('model_type', $owner->getMorphClass())
                ->where('model_id', $owner->getKey());
        } else {
            $query->whereNull('model_type')->whereNull('model_id');
        }

        return (int) $query->max('order_column') + 1;
    }

    private function draftTtl(): int
    {
        return MediaConfig::draftTtl();
    }
}
