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
use RoundlyConsulting\MediaLibrary\Events\MediaHasBeenAdded;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Placeholders\PlaceholderGenerator;
use RoundlyConsulting\MediaLibrary\Support\Checksum;
use RoundlyConsulting\MediaLibrary\Support\DiskResolver;
use RoundlyConsulting\MediaLibrary\Support\ExifOrientation;
use RoundlyConsulting\MediaLibrary\Support\FileNames;
use RoundlyConsulting\MediaLibrary\Support\ImageDecodeGuard;
use RoundlyConsulting\MediaLibrary\Support\MediaConfig;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;
use RoundlyConsulting\MediaLibrary\Support\StoredFiles;
use RoundlyConsulting\MediaLibrary\Variants\VariantResolver;
use RoundlyConsulting\PackageToolkit\Support\Config;
use Throwable;

/**
 * Persists a normalized source file as a {@see Media} row and writes its bytes through
 * Laravel's filesystem, honouring the bucket's storage/visibility/acceptance rules.
 *
 * The bucket's mime allowlist, size cap and image dimensions are checked before anything is
 * written; a `singleFile()` bucket's previous media is removed only once the new one is stored.
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
        $bucket = $this->guard->bucketFor($state->owner, $state->bucket);

        // What a viewer sees: a phone photo stored sideways with an EXIF turn records upright.
        $dimensions = str_starts_with((string) $state->file->mimeType, 'image/')
            ? ExifOrientation::displayDimensions($state->file->path)
            : null;

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

        $checksum = $this->checksum->forLocalFile($state->file->path);
        $media->checksum = $checksum;

        if ($dimensions !== null) {
            [$media->width, $media->height] = $dimensions;
        }

        // LQIP placeholders read the local source, which storeOriginal() then discards.
        $this->capturePlaceholders($media, $state);

        $this->storeOriginal($media, $state, $disk, $visibility, $checksum);

        $media->save();

        if (! $state->draft && $state->owner instanceof Model) {
            $this->guard->enforceSingleFile($bucket, $state->owner, $state->bucket, $media);
        }

        event(new MediaHasBeenAdded($media));

        $this->generateVariantsFor($media, $bucket, $state);

        return $media;
    }

    /**
     * For image media, compute the LQIP placeholders from the local source. Any image-decoding
     * failure is swallowed so a quirky file never blocks the upload itself.
     */
    private function capturePlaceholders(Media $media, PendingFileAddState $state): void
    {
        // Only raster images are ever decoded (an SVG never is).
        if (! ImageDecodeGuard::decodes($media->mime_type)) {
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

        $media->uuid = (string) Str::uuid();
        $media->bucket_name = $state->bucket;
        $media->name = $state->name ?? $state->file->name;
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
     * Store the original's bytes, deduplicating when an identical `(disk, visibility, checksum)`
     * already exists: a deduped row points its `path` at the canonical file and writes nothing.
     */
    private function storeOriginal(
        Media $media,
        PendingFileAddState $state,
        string $disk,
        string $visibility,
        ?string $checksum,
    ): void {
        $canonical = $this->dedupCanonical($disk, $visibility, $checksum);

        if ($canonical !== null) {
            $this->files->pin($canonical);
            $media->path = $canonical->getPath();

            $this->discardSource($state);

            return;
        }

        $target = $this->files->freePath($media, $disk);
        $media->path = $target;

        $stream = fopen($state->file->path, 'rb');

        if ($stream === false) {
            return;
        }

        Storage::disk($disk)->put($target, $stream, ['visibility' => $visibility]);

        if (is_resource($stream)) {
            fclose($stream);
        }

        $this->discardSource($state);
    }

    private function dedupCanonical(string $disk, string $visibility, ?string $checksum): ?Media
    {
        if ($checksum === null || ! Config::boolean('media.deduplicate', true)) {
            return null;
        }

        return $this->checksum->canonicalFor($disk, $visibility, $checksum);
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
