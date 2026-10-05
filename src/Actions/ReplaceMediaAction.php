<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Buckets\BucketGuard;
use RoundlyConsulting\MediaLibrary\Buckets\FileAdderFactory;
use RoundlyConsulting\MediaLibrary\Contracts\ImageDriver;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\AddedFile;
use RoundlyConsulting\MediaLibrary\Events\MediaHasBeenReplaced;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Placeholders\PlaceholderGenerator;
use RoundlyConsulting\MediaLibrary\Support\Checksum;
use RoundlyConsulting\MediaLibrary\Support\ExifOrientation;
use RoundlyConsulting\MediaLibrary\Support\StoredFiles;
use RoundlyConsulting\PackageToolkit\Support\Config;
use Throwable;

/**
 * Replaces a media's underlying original: the row keeps its `id` and `uuid`, while its bytes and
 * all derived metadata (checksum, size, mime, extension, dimensions, placeholders) are recomputed
 * and its variants are regenerated. The new file must satisfy the owner's bucket (mime allowlist,
 * size cap, dimensions), exactly like an add.
 *
 * The URL stays the same whenever the old original was this row's alone: the new bytes overwrite
 * it in place. An original other rows still point at (a deduplicated upload, an `attach()`, a
 * same-disk `copy()`) is NEVER overwritten — that would silently change their media — so the new
 * bytes go to a free path of this row's own and only this row's URL changes. New bytes identical
 * to an already-stored file reuse that file (`media.deduplicate`). The old original is removed
 * only when no other row, soft-deleted ones included, still points at it.
 */
final class ReplaceMediaAction
{
    public function __construct(
        private readonly FileAdderFactory $fileAdderFactory,
        private readonly Checksum $checksum,
        private readonly PlaceholderGenerator $placeholders,
        private readonly DispatchVariantsAction $dispatchVariants,
        private readonly BucketGuard $guard,
        private readonly StoredFiles $files,
    ) {}

    /**
     * @throws FileUnacceptableForBucket when the owner's bucket does not accept the new file
     */
    public function execute(Media $media, string|UploadedFile $file): Media
    {
        $source = $this->fileAdderFactory->fromFile($file);

        try {
            return $this->replace($media, $source);
        } finally {
            $this->discardSource($source);
        }
    }

    private function replace(Media $media, AddedFile $source): Media
    {
        $dimensions = str_starts_with((string) $source->mimeType, 'image/')
            ? ExifOrientation::displayDimensions($source->path)
            : null;

        $this->guard->ensureAccepts(
            $this->guard->bucketFor($media->model, $media->bucket_name),
            $media->bucket_name,
            $source->mimeType,
            $source->size,
            $dimensions[0] ?? null,
            $dimensions[1] ?? null,
        );

        $oldPath = $media->getPath();
        $oldDisk = $media->disk;
        $oldShared = $this->files->originalIsShared($media);

        $this->files->deleteVariants($media);

        $newChecksum = $this->checksum->forLocalFile($source->path);

        $this->refreshMetadata($media, $source, $newChecksum, $dimensions);

        $this->writeOriginal($media, $source, $newChecksum, $oldPath, $oldShared);

        $media->generated_variants = [];
        $media->save();

        $this->releaseOldOriginal($oldDisk, $oldPath, $oldShared, $media);

        $this->dispatchVariants->execute($media, $media->isImage() ? $media->resolveVariants() : []);

        event(new MediaHasBeenReplaced($media));

        return $media;
    }

    /**
     * @param  array{0: int, 1: int}|null  $dimensions
     */
    private function refreshMetadata(Media $media, AddedFile $source, ?string $checksum, ?array $dimensions): void
    {
        $media->mime_type = $source->mimeType;
        $media->extension = $source->extension;
        $media->size = $source->size;
        $media->checksum = $checksum;
        // file_name stays stable so the public URL doesn't change; placeholders are recomputed
        // (or left null for non-images) below.
        $media->width = $dimensions[0] ?? null;
        $media->height = $dimensions[1] ?? null;
        $media->placeholders = null;

        if (! $media->isImage()) {
            return;
        }

        try {
            $computed = $this->placeholders->forLocalImage($source->path, app(ImageDriver::class));

            if ($computed !== []) {
                $media->placeholders = $computed;
            }
        } catch (Throwable) {
            // A decode/driver failure must not fail the replace — the media simply has no placeholder.
        }
    }

    /**
     * Write the new bytes: onto an identical existing file when dedup finds one, else in place when
     * the old original was this row's alone, else onto a free path of this row's own.
     */
    private function writeOriginal(Media $media, AddedFile $source, ?string $checksum, string $oldPath, bool $oldShared): void
    {
        $canonical = $this->dedupCanonical($media, $checksum);

        if ($canonical !== null) {
            $this->files->pin($canonical);
            $media->path = $canonical->getPath();

            return;
        }

        $target = $oldShared ? $this->files->freePath($media, $media->disk) : $oldPath;
        $media->path = $target;

        $stream = fopen($source->path, 'rb');

        if ($stream === false) {
            return;
        }

        Storage::disk($media->disk)->put($target, $stream, ['visibility' => $media->visibility]);

        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    private function dedupCanonical(Media $media, ?string $checksum): ?Media
    {
        if ($checksum === null || ! Config::boolean('media.deduplicate', true)) {
            return null;
        }

        return $this->checksum->canonicalFor($media->disk, $media->visibility, $checksum, (int) $media->getKey());
    }

    /** Remove the OLD original — unless another row still points at it, or it is the file just written. */
    private function releaseOldOriginal(string $oldDisk, string $oldPath, bool $oldShared, Media $media): void
    {
        if ($oldShared || ($oldDisk === $media->disk && $oldPath === $media->getPath())) {
            return;
        }

        // After the commit: a host rollback restores the row, still pointing at this file.
        $this->files->deleteAfterCommit($media, $oldDisk, $oldPath);
    }

    private function discardSource(AddedFile $source): void
    {
        if ($source->isTemporary && is_file($source->path)) {
            @unlink($source->path);
        }
    }
}
