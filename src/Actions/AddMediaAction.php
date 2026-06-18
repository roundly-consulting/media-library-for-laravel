<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RoundlyConsulting\MediaLibrary\Buckets\MediaBucket;
use RoundlyConsulting\MediaLibrary\Buckets\PendingFileAddState;
use RoundlyConsulting\MediaLibrary\Contracts\FileNamer;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\MediaLibrary\Contracts\PathGenerator;
use RoundlyConsulting\MediaLibrary\Events\MediaHasBeenAdded;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\DiskResolver;

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
    ) {}

    public function execute(PendingFileAddState $state): Media
    {
        $bucket = $this->resolveBucket($state->owner, $state->bucket);

        $this->guardAcceptedMimeType($bucket, $state->bucket, $state->file->mimeType);

        $disk = $this->diskResolver->resolveOriginalDisk($state->diskOverride, $bucket);
        $variantsDisk = $this->diskResolver->resolveVariantsDisk($state->variantsDiskOverride, $bucket, $disk);

        $visibility = $this->resolveVisibility($state->visibility, $bucket);

        if ($bucket !== null && $bucket->isSingleFile() && $state->owner instanceof Model) {
            $this->clearBucket($state->owner, $state->bucket);
        }

        $media = $this->makeMedia($state, $bucket, $disk, $variantsDisk, $visibility);

        $this->writeFile($media, $state, $disk, $visibility);

        $media->save();

        event(new MediaHasBeenAdded($media));

        return $media;
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

        /** @var class-string<Media> $modelClass */
        $modelClass = config('media.media_model');
        $media = new $modelClass;

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

        if ($state->owner instanceof Model) {
            $media->model_type = $state->owner->getMorphClass();
            $media->model_id = $state->owner->getKey();
        }

        return $media;
    }

    private function writeFile(Media $media, PendingFileAddState $state, string $disk, string $visibility): void
    {
        $target = $this->pathGenerator->getPath($media).$media->file_name;

        $stream = fopen($state->file->path, 'rb');

        if ($stream === false) {
            return;
        }

        Storage::disk($disk)->put($target, $stream, ['visibility' => $visibility]);

        if (is_resource($stream)) {
            fclose($stream);
        }

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
        $query = Media::query()->where('bucket_name', $bucket);

        if ($owner instanceof Model) {
            $query->where('model_type', $owner->getMorphClass())
                ->where('model_id', $owner->getKey());
        } else {
            $query->whereNull('model_type')->whereNull('model_id');
        }

        return (int) $query->max('order_column') + 1;
    }

    private function clearBucket(Model $owner, string $bucket): void
    {
        Media::query()
            ->where('model_type', $owner->getMorphClass())
            ->where('model_id', $owner->getKey())
            ->where('bucket_name', $bucket)
            ->get()
            ->each(fn (Media $media): mixed => $media->forceDelete());
    }
}
