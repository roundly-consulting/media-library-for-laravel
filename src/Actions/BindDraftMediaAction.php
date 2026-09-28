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
 * when the draft's TTL has lapsed and {@see FileUnacceptableForBucket} when the bucket refuses it.
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

        foreach ($cleanup as [$disk, $path]) {
            $this->fileTransfer->delete($disk, $path);
        }

        $this->guard->enforceSingleFile($target, $owner, $bucket, $media);

        event(new DraftMediaHasBeenBound($media));

        $media->setRelation('model', $owner);
        $this->reconcileVariants->execute($media);

        return $media;
    }

    /**
     * Put the draft's files where the bucket stores media. Returns the source files to delete
     * once the row points at their replacements.
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

        // Variants rendered for the draft are re-rendered on the new storage by the reconcile.
        $this->files->deleteVariants($media);
        $media->generated_variants = [];
        $media->variants_disk = $variantsDisk;

        if ($disk === $media->disk && $visibility === $media->visibility) {
            return [];
        }

        $sourceDisk = $media->disk;
        $sourcePath = $media->getPath();
        $shared = $this->files->originalIsShared($media);

        if ($disk === $sourceDisk && ! $shared) {
            Storage::disk($disk)->setVisibility($sourcePath, $visibility);
            $media->visibility = $visibility;

            return [];
        }

        $targetPath = $this->files->freePath($media, $disk, $disk === $sourceDisk ? null : $sourcePath);

        $this->fileTransfer->copy($sourceDisk, $sourcePath, $disk, $targetPath, $visibility);
        Storage::disk($disk)->setVisibility($targetPath, $visibility);

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
