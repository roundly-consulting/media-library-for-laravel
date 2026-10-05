<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Buckets\BucketGuard;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\MediaLibrary\Events\MediaHasBeenMoved;
use RoundlyConsulting\MediaLibrary\Exceptions\FileCannotBeWritten;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\DiskResolver;
use RoundlyConsulting\MediaLibrary\Support\FileTransfer;
use RoundlyConsulting\MediaLibrary\Support\StoredFiles;
use Throwable;

/**
 * Relocates a {@see Media} — across disks, and/or to a different owning model and bucket
 * (including model → global and global → model) — moving the original and its generated
 * variants and re-pointing the row. Moving only the variant files is
 * {@see MoveMediaVariantsAction}.
 *
 * The DB update runs inside a transaction; the physical source files are deleted only after the
 * transaction commits — the outermost one, when a host wraps the move in its own — so a rollback
 * never orphans the row from its files.
 *
 * The source original is refcount-guarded: while another row (soft-deleted ones included) still
 * points at it, a cross-disk move COPIES it and leaves the source in place. On the target disk the
 * original keeps its relative path unless another row already points at that path there. Variants
 * are per-row: those stored on the original's disk follow it.
 *
 * Entering a different owner or bucket applies that bucket: its acceptance rules are checked first,
 * its single-file rule is enforced, variants it does not define are dropped and the ones it defines
 * are generated.
 */
final class MoveMediaAction
{
    public function __construct(
        private readonly DiskResolver $diskResolver,
        private readonly FileTransfer $fileTransfer,
        private readonly StoredFiles $files,
        private readonly BucketGuard $guard,
        private readonly ReconcileVariantsAction $reconcileVariants,
    ) {}

    /**
     * `$keepOwner` makes it a disk-only move: `$toModel` and `$bucket` are ignored, the owner and
     * bucket stay exactly as stored — even when the owner is soft-deleted or gone, which the
     * `model` relation cannot tell from "no owner" — and the variants are not reconciled.
     *
     * @throws FileUnacceptableForBucket when re-homing into a bucket that does not accept the media
     * @throws FileCannotBeWritten when the target disk refuses a write (nothing is changed)
     */
    public function execute(
        Media $media,
        HasMedia|Model|null $toModel = null,
        string $bucket = 'default',
        ?string $disk = null,
        bool $keepOwner = false,
    ): Media {
        $targetDisk = $disk ?? $media->disk;
        $this->diskResolver->ensureDiskExists($targetDisk);

        $rehomed = ! $keepOwner && $this->isRehome($media, $toModel, $bucket);
        $targetBucket = $rehomed ? $this->guard->bucketFor($toModel, $bucket) : null;

        if ($rehomed) {
            $this->guard->ensureAcceptsMedia($targetBucket, $bucket, $media);
        }

        $sourceDisk = $media->disk;
        $targetPath = $media->getPath();
        $cleanup = [];

        if ($targetDisk !== $sourceDisk) {
            [$targetPath, $cleanup] = $this->relocateFiles($media, $sourceDisk, $targetDisk);
        }

        DB::transaction(function () use ($media, $toModel, $bucket, $targetDisk, $sourceDisk, $targetPath, $rehomed): void {
            if ($rehomed) {
                $this->rehome($media, $toModel, $bucket);
            }

            if ($targetDisk !== $sourceDisk) {
                $this->followOriginal($media, $sourceDisk, $targetDisk);
                $media->path = $targetPath;
                $media->disk = $targetDisk;
            }

            $media->save();
        });

        // After the commit — the host's, when the move runs inside one — so a rollback never
        // leaves the row pointing at deleted files.
        foreach ($cleanup as [$cleanupDisk, $cleanupPath]) {
            $this->files->deleteAfterCommit($media, $cleanupDisk, $cleanupPath);
        }

        if ($rehomed) {
            $media->setRelation('model', $toModel instanceof Model ? $toModel : null);

            if ($toModel instanceof Model) {
                $this->guard->enforceSingleFile($targetBucket, $toModel, $bucket, $media);
            }

            $this->reconcileVariants->execute($media);
        }

        event(new MediaHasBeenMoved($media));

        return $media;
    }

    private function isRehome(Media $media, HasMedia|Model|null $toModel, string $bucket): bool
    {
        $type = $toModel instanceof Model ? $toModel->getMorphClass() : null;
        $id = $toModel instanceof Model ? (string) $toModel->getKey() : null;
        $currentId = $media->model_id === null ? null : (string) $media->model_id;

        return $media->bucket_name !== $bucket || $media->model_type !== $type || $currentId !== $id;
    }

    /**
     * Copy the original (and the variants that live on its disk) onto the target disk. Returns the
     * original's path there and the source files to delete once the row is committed.
     *
     * A refused write throws before the row changes or any source is touched, and takes back the
     * copies already written to the target.
     *
     * @return array{0: string, 1: list<array{0: string, 1: string}>}
     */
    private function relocateFiles(Media $media, string $sourceDisk, string $targetDisk): array
    {
        $sourcePath = $media->getPath();
        $targetPath = $this->files->freePath($media, $targetDisk, $sourcePath);
        $written = [];

        try {
            $this->fileTransfer->copyOrFail($sourceDisk, $sourcePath, $targetDisk, $targetPath, $media->visibility);
            $written[] = $targetPath;

            $cleanup = $this->files->originalIsShared($media) ? [] : [[$sourceDisk, $sourcePath]];

            foreach ($this->followingVariants($media, $sourceDisk) as $name) {
                $path = $media->getPath($name);

                // A variant whose file is already gone has nothing to carry over.
                if (! Storage::disk($sourceDisk)->exists($path)) {
                    continue;
                }

                $this->fileTransfer->copyOrFail($sourceDisk, $path, $targetDisk, $path, $media->visibility);
                $written[] = $path;
                $cleanup[] = [$sourceDisk, $path];
            }
        } catch (Throwable $exception) {
            foreach ($written as $path) {
                $this->fileTransfer->delete($targetDisk, $path);
            }

            throw $exception;
        }

        return [$targetPath, $cleanup];
    }

    /** Re-record the variants that followed the original onto the target disk. */
    private function followOriginal(Media $media, string $sourceDisk, string $targetDisk): void
    {
        $records = $media->generatedVariants();

        foreach ($this->followingVariants($media, $sourceDisk) as $name) {
            $media->recordGeneratedVariant($name, $records[$name]->onDisk($targetDisk));
        }
    }

    /**
     * The variants that move with the original: those written to its disk when the media has no
     * separate variants disk.
     *
     * @return list<string>
     */
    private function followingVariants(Media $media, string $sourceDisk): array
    {
        if ($media->variants_disk !== null) {
            return [];
        }

        $names = [];

        foreach ($media->generatedVariants() as $name => $variant) {
            if ($variant->disk === $sourceDisk) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function rehome(Media $media, HasMedia|Model|null $toModel, string $bucket): void
    {
        $media->bucket_name = $bucket;

        if ($toModel instanceof Model) {
            $media->model_type = $toModel->getMorphClass();
            $media->model_id = $toModel->getKey();
        } else {
            $media->model_type = null;
            $media->model_id = null;
        }
    }
}
