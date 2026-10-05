<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Buckets\BucketGuard;
use RoundlyConsulting\MediaLibrary\Buckets\MediaBucket;
use RoundlyConsulting\MediaLibrary\Events\DraftMediaHasBeenBound;
use RoundlyConsulting\MediaLibrary\Exceptions\DraftMediaExpired;
use RoundlyConsulting\MediaLibrary\Exceptions\DraftMediaNotFound;
use RoundlyConsulting\MediaLibrary\Exceptions\FileCannotBeWritten;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\DiskResolver;
use RoundlyConsulting\MediaLibrary\Support\FileTransfer;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;
use RoundlyConsulting\MediaLibrary\Support\StoredFiles;

/**
 * Binds an unbound draft media (matched by its opaque token) to an owning model: sets the
 * polymorphic owner and bucket, clears the draft token/expiry, and persists.
 *
 * A draft is uploaded before its bucket is known, so binding is where the bucket applies: its
 * acceptance rules are checked, the original moves to the bucket's disk and visibility when it
 * declares them (a shared, deduplicated original is copied, never moved), its single-file rule
 * is enforced and its variants are generated.
 *
 * Throws {@see DraftMediaNotFound} when no draft matches the token, {@see DraftMediaExpired}
 * when the draft's TTL has lapsed, {@see FileUnacceptableForBucket} when the bucket refuses it and
 * {@see FileCannotBeWritten} when the bucket's disk refuses the original (the draft stays as it was).
 */
final class BindDraftMediaAction
{
    public function __construct(
        private readonly BucketGuard $guard,
        private readonly DiskResolver $diskResolver,
        private readonly FileTransfer $fileTransfer,
        private readonly StoredFiles $files,
        private readonly ReconcileVariantsAction $reconcileVariants,
    ) {}

    public function execute(Model $owner, string $token, string $bucket = 'default'): Media
    {
        $media = $this->findDraft($token);

        $this->guardNotExpired($media, $token);

        $target = $this->guard->bucketFor($owner, $bucket);
        $this->guard->ensureAcceptsMedia($target, $bucket, $media);

        $cleanup = $this->applyBucketStorage($media, $target);

        $media->model_type = $owner->getMorphClass();
        $media->model_id = $owner->getKey();
        $media->bucket_name = $bucket;
        $media->draft_token = null;
        $media->draft_expires_at = null;
        $media->save();

        // After the commit — a host binding inside its own transaction may still roll back.
        foreach ($cleanup as [$disk, $path]) {
            $this->files->deleteAfterCommit($media, $disk, $path);
        }

        $this->guard->enforceSingleFile($target, $owner, $bucket, $media);

        event(new DraftMediaHasBeenBound($media));

        $media->setRelation('model', $owner);
        $this->reconcileVariants->execute($media);

        return $media;
    }

    /**
     * Put the draft's files where the bucket stores media. Returns the files to delete once the
     * row points at their replacements (the draft's original when it moved, and its variants).
     *
     * @return list<array{0: string, 1: string}>
     */
    private function applyBucketStorage(Media $media, ?MediaBucket $bucket): array
    {
        if ($bucket === null) {
            return [];
        }

        $disk = $bucket->getDisk() ?? $media->disk;
        $visibility = $bucket->getVisibility() ?? $media->visibility;
        $variantsDisk = $this->diskResolver->resolveVariantsDisk(null, $bucket, $disk);
        $variantsDisk = $variantsDisk === $disk ? null : $variantsDisk;

        if ($disk === $media->disk && $visibility === $media->visibility && $variantsDisk === $media->variants_disk) {
            return [];
        }

        $this->diskResolver->ensureDiskExists($disk);

        // The original goes first: a refused write throws while the draft is still whole.
        $cleanup = $this->relocateOriginal($media, $disk, $visibility);

        // Variants rendered for the draft are re-rendered on the new storage by the reconcile; the
        // old files go with the rest of the cleanup.
        foreach (array_keys($media->generatedVariants()) as $name) {
            $cleanup[] = [$media->diskFor($name), $media->getPath($name)];
        }

        $media->generated_variants = [];
        $media->variants_disk = $variantsDisk;

        return $cleanup;
    }

    /**
     * Put the original on the bucket's disk with the bucket's visibility. Returns the source
     * files to delete once the row points at their replacement.
     *
     * @return list<array{0: string, 1: string}>
     *
     * @throws FileCannotBeWritten when the disk refuses the write or the visibility change
     */
    private function relocateOriginal(Media $media, string $disk, string $visibility): array
    {
        if ($disk === $media->disk && $visibility === $media->visibility) {
            return [];
        }

        $sourceDisk = $media->disk;
        $sourcePath = $media->getPath();
        $shared = $this->files->originalIsShared($media);

        if ($disk === $sourceDisk && ! $shared) {
            if (! Storage::disk($disk)->setVisibility($sourcePath, $visibility)) {
                throw FileCannotBeWritten::toDisk($sourcePath, $disk);
            }

            $media->visibility = $visibility;

            return [];
        }

        $targetPath = $this->files->freePath($media, $disk, $disk === $sourceDisk ? null : $sourcePath);

        $this->fileTransfer->copyOrFail($sourceDisk, $sourcePath, $disk, $targetPath, $visibility);

        if (! Storage::disk($disk)->setVisibility($targetPath, $visibility)) {
            $this->fileTransfer->delete($disk, $targetPath);

            throw FileCannotBeWritten::toDisk($targetPath, $disk);
        }

        $media->disk = $disk;
        $media->path = $targetPath;
        $media->visibility = $visibility;

        return $shared ? [] : [[$sourceDisk, $sourcePath]];
    }

    private function findDraft(string $token): Media
    {
        $media = MediaModel::query()
            ->whereNotNull('draft_token')
            ->where('draft_token', $token)
            ->first();

        if (! $media instanceof Media) {
            throw DraftMediaNotFound::forToken($token);
        }

        return $media;
    }

    private function guardNotExpired(Media $media, string $token): void
    {
        $expiresAt = $media->draft_expires_at;

        if ($expiresAt instanceof CarbonInterface && $expiresAt->isPast()) {
            throw DraftMediaExpired::forToken($token);
        }
    }
}
