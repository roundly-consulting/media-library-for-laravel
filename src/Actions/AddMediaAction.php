<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RoundlyConsulting\MediaLibrary\Buckets\MediaBucket;
use RoundlyConsulting\MediaLibrary\Buckets\PendingFileAddState;
use RoundlyConsulting\MediaLibrary\Contracts\FileNamer;
use RoundlyConsulting\MediaLibrary\Contracts\ImageDriver;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\MediaLibrary\Contracts\PathGenerator;
use RoundlyConsulting\MediaLibrary\Events\MediaHasBeenAdded;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\MediaLibrary\Jobs\GenerateVariantsJob;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Placeholders\PlaceholderGenerator;
use RoundlyConsulting\MediaLibrary\Support\Checksum;
use RoundlyConsulting\MediaLibrary\Support\DiskResolver;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;
use RoundlyConsulting\MediaLibrary\Variants\Variant;
use RoundlyConsulting\MediaLibrary\Variants\VariantResolver;
use Throwable;

/**
 * Persists a normalized source file as a {@see Media} row and writes its bytes through
 * Laravel's filesystem, honouring the bucket's storage/visibility/acceptance rules.
 */
final class AddMediaAction
{
    public function __construct(
        private readonly DiskResolver $diskResolver,
        private readonly PathGenerator $pathGenerator,
        private readonly FileNamer $fileNamer,
        private readonly GenerateVariantsAction $generateVariants,
        private readonly VariantResolver $variantResolver,
        private readonly Checksum $checksum,
        private readonly PlaceholderGenerator $placeholders,
    ) {}

    public function execute(PendingFileAddState $state): Media
    {
        $bucket = $this->resolveBucket($state->owner, $state->bucket);

        $this->guardAcceptedMimeType($bucket, $state->bucket, $state->file->mimeType);

        $disk = $this->diskResolver->resolveOriginalDisk($state->diskOverride, $bucket);
        $variantsDisk = $this->diskResolver->resolveVariantsDisk($state->variantsDiskOverride, $bucket, $disk);

        $visibility = $this->resolveVisibility($state->visibility, $bucket);

        if (! $state->draft && $bucket !== null && $bucket->isSingleFile() && $state->owner instanceof Model) {
            $this->clearBucket($state->owner, $state->bucket);
        }

        $media = $this->makeMedia($state, $bucket, $disk, $variantsDisk, $visibility);

        $checksum = $this->checksum->forLocalFile($state->file->path);
        $media->checksum = $checksum;

        // Dimensions + LQIP placeholders read the local source, which storeOriginal() then discards.
        $this->captureImageMetadata($media, $state);

        $this->storeOriginal($media, $state, $disk, $visibility, $checksum);

        $media->save();

        event(new MediaHasBeenAdded($media));

        $this->generateVariantsFor($media, $bucket, $state);

        return $media;
    }

    /**
     * For image media, read pixel dimensions and compute the LQIP placeholders from the local
     * source. Non-images are skipped (dimensions/placeholders stay null). Any image-decoding
     * failure is swallowed so a quirky file never blocks the upload itself.
     */
    private function captureImageMetadata(Media $media, PendingFileAddState $state): void
    {
        if (! $media->isImage()) {
            return;
        }

        $dimensions = @getimagesize($state->file->path);

        if (is_array($dimensions)) {
            $media->width = $dimensions[0];
            $media->height = $dimensions[1];
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
        if ($bucket === null || ! $media->isImage() || ! $state->owner instanceof HasMedia) {
            return;
        }

        $variants = $this->variantResolver->forOwnerBucket($state->owner, $state->bucket, $media);

        if ($variants === []) {
            return;
        }

        $sync = [];
        $queued = [];

        foreach ($variants as $variant) {
            if ($this->shouldQueue($variant)) {
                $queued[] = $variant->name;
            } else {
                $sync[] = $variant;
            }
        }

        if ($sync !== []) {
            $this->generateVariants->execute($media, $sync);
        }

        if ($queued !== []) {
            $this->dispatchQueued($media, $queued, $state);
        }
    }

    private function shouldQueue(Variant $variant): bool
    {
        $perVariant = $variant->isQueued();

        if ($perVariant !== null) {
            return $perVariant;
        }

        return config('media.queue_variants_by_default') === true;
    }

    /**
     * @param  list<string>  $variantNames
     */
    private function dispatchQueued(Media $media, array $variantNames, PendingFileAddState $state): void
    {
        $job = new GenerateVariantsJob((int) $media->getKey(), $variantNames);

        $connection = config('media.queue_connection');
        $queue = $state->queue ?? config('media.queue_name');

        if (is_string($connection)) {
            $job->onConnection($connection);
        }

        if (is_string($queue)) {
            $job->onQueue($queue);
        }

        dispatch($job);
    }

    private function makeMedia(
        PendingFileAddState $state,
        ?MediaBucket $bucket,
        string $disk,
        string $variantsDisk,
        string $visibility,
    ): Media {
        $fileName = $this->fileNamer->originalFileName(
            $state->fileName ?? $state->file->fileName
        );

        $media = MediaModel::new();

        $media->uuid = (string) Str::uuid();
        $media->bucket_name = $state->bucket;
        $media->name = $state->name ?? $state->file->name;
        $media->file_name = $fileName;
        $media->mime_type = $state->file->mimeType;
        $media->extension = $state->file->extension;
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
            $media->path = $canonical->getPath();

            $this->discardSource($state);

            return;
        }

        $target = $this->pathGenerator->getPath($media).$media->file_name;
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
        if ($checksum === null || config('media.deduplicate') !== true) {
            return null;
        }

        return $this->checksum->canonicalFor($disk, $visibility, $checksum);
    }

    private function discardSource(PendingFileAddState $state): void
    {
        if (! $state->preserveOriginal && $state->file->isTemporary && is_file($state->file->path)) {
            @unlink($state->file->path);
        }
    }

    private function resolveBucket(HasMedia|Model|null $owner, string $bucketName): ?MediaBucket
    {
        if (! $owner instanceof HasMedia) {
            return null;
        }

        return $owner->resolveMediaBucket($bucketName);
    }

    private function guardAcceptedMimeType(?MediaBucket $bucket, string $bucketName, ?string $mimeType): void
    {
        if ($bucket !== null && ! $bucket->accepts($mimeType)) {
            throw FileUnacceptableForBucket::mimeType($mimeType ?? 'unknown', $bucketName);
        }
    }

    private function resolveVisibility(?string $override, ?MediaBucket $bucket): string
    {
        $default = config('media.default_visibility');
        $default = is_string($default) ? $default : 'public';

        return $override ?? $bucket?->getVisibility() ?? $default;
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
        $ttl = config('media.drafts.ttl');

        return is_numeric($ttl) ? (int) $ttl : 1440;
    }

    private function clearBucket(Model $owner, string $bucket): void
    {
        MediaModel::query()
            ->where('model_type', $owner->getMorphClass())
            ->where('model_id', $owner->getKey())
            ->where('bucket_name', $bucket)
            ->get()
            ->each(fn (Media $media): mixed => $media->forceDelete());
    }
}
