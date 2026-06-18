<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Buckets\FileAdderFactory;
use RoundlyConsulting\MediaLibrary\Contracts\PathGenerator;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\AddedFile;
use RoundlyConsulting\MediaLibrary\Events\MediaHasBeenReplaced;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Placeholders\PlaceholderGenerator;
use RoundlyConsulting\MediaLibrary\Support\Checksum;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\ImageDriverFactory;
use Throwable;

/**
 * Replaces a media's underlying original in place: the row keeps its `id`, `uuid`, and public URL,
 * so existing links/embeds keep working, while its bytes and all derived metadata (checksum, size,
 * mime, extension, dimensions, placeholders) are recomputed and its variants are regenerated.
 *
 * Dedup is respected on both sides: releasing the OLD original is refcount-guarded (a still-shared
 * file is left for its other referrers), and writing the NEW original goes through the same
 * `(disk, visibility, checksum)` dedup as a fresh add (reuse the canonical file when it already
 * exists, otherwise write under this row's own uuid path).
 */
final class ReplaceMediaAction
{
    public function __construct(
        private readonly FileAdderFactory $fileAdderFactory,
        private readonly PathGenerator $pathGenerator,
        private readonly Checksum $checksum,
        private readonly PlaceholderGenerator $placeholders,
        private readonly GenerateVariantsAction $generateVariants,
    ) {}

    public function execute(Media $media, string|UploadedFile $file): Media
    {
        $source = $this->fileAdderFactory->fromFile(null, $file)->addedFile();

        $oldPath = $media->getPath();
        $oldDisk = $media->disk;
        $oldSharedByOthers = $this->checksum->isSharedByOthers($media);

        $this->removeOwnVariants($media);

        $newChecksum = $this->checksum->forLocalFile($source->path);

        $this->refreshMetadata($media, $source, $newChecksum);
        $this->captureImageMetadata($media, $source);

        $this->writeOriginal($media, $source, $newChecksum);

        $media->generated_variants = [];
        $media->save();

        $this->releaseOldOriginal($oldDisk, $oldPath, $oldSharedByOthers, $media);

        $this->discardSource($source);

        $this->regenerateVariants($media);

        event(new MediaHasBeenReplaced($media));

        return $media;
    }

    private function refreshMetadata(Media $media, AddedFile $source, ?string $checksum): void
    {
        $media->mime_type = $source->mimeType;
        $media->extension = $source->extension;
        $media->size = $source->size;
        $media->checksum = $checksum;
        // file_name stays stable so the public URL doesn't change; width/height/placeholders are
        // reset here and recomputed (or left null for non-images) by captureImageMetadata().
        $media->width = null;
        $media->height = null;
        $media->placeholders = null;
    }

    private function captureImageMetadata(Media $media, AddedFile $source): void
    {
        if (! $media->isImage()) {
            return;
        }

        $dimensions = @getimagesize($source->path);

        if (is_array($dimensions)) {
            $media->width = $dimensions[0];
            $media->height = $dimensions[1];
        }

        try {
            $computed = $this->placeholders->forLocalImage($source->path, ImageDriverFactory::make());

            if ($computed !== []) {
                $media->placeholders = $computed;
            }
        } catch (Throwable) {
            // A decode/driver failure must not fail the replace — the media simply has no placeholder.
        }
    }

    /**
     * Write the new bytes, deduplicating when an identical `(disk, visibility, checksum)` already
     * exists on another row. Otherwise the bytes are written under this row's own uuid path.
     */
    private function writeOriginal(Media $media, AddedFile $source, ?string $checksum): void
    {
        $canonical = $this->dedupCanonical($media, $checksum);

        if ($canonical !== null) {
            $media->path = $canonical->getPath();

            return;
        }

        $target = $this->pathGenerator->getPath($media).$media->file_name;
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
        if ($checksum === null || config('media.deduplicate') !== true) {
            return null;
        }

        return $this->checksum->canonicalFor($media->disk, $media->visibility, $checksum, (int) $media->getKey());
    }

    /**
     * Remove the OLD original — but only when no other row still references it, and only when it
     * lived under this row's own uuid (a deduped pointer to a canonical file is never deleted here).
     */
    private function releaseOldOriginal(string $oldDisk, string $oldPath, bool $oldSharedByOthers, Media $media): void
    {
        if ($oldSharedByOthers) {
            return;
        }

        // The new write may have landed on the very same path (same bytes round-tripped) — never
        // delete the file we just wrote.
        if ($oldDisk === $media->disk && $oldPath === $media->getPath()) {
            return;
        }

        if (! str_starts_with($oldPath, $media->uuid.'/')) {
            return;
        }

        Storage::disk($oldDisk)->delete($oldPath);
    }

    private function removeOwnVariants(Media $media): void
    {
        $disk = Storage::disk($media->variants_disk ?? $media->disk);

        foreach (array_keys($media->generated_variants ?? []) as $name) {
            $disk->delete($media->getPath((string) $name));
        }
    }

    private function regenerateVariants(Media $media): void
    {
        if (! $media->isImage()) {
            return;
        }

        $variants = $media->resolveVariants();

        if ($variants !== []) {
            $this->generateVariants->execute($media, $variants);
        }
    }

    private function discardSource(AddedFile $source): void
    {
        if ($source->isTemporary && is_file($source->path)) {
            @unlink($source->path);
        }
    }
}
