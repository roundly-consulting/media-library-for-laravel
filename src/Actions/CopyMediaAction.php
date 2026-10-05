<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RoundlyConsulting\MediaLibrary\Buckets\BucketGuard;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\MediaLibrary\Events\MediaHasBeenAdded;
use RoundlyConsulting\MediaLibrary\Exceptions\FileCannotBeWritten;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\DiskResolver;
use RoundlyConsulting\MediaLibrary\Support\FileTransfer;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;
use RoundlyConsulting\MediaLibrary\Support\StoredFiles;

/**
 * Duplicates a {@see Media} into a new row with a fresh uuid, optionally onto a different disk
 * and/or under a different owning model and bucket. The source files are preserved.
 *
 * On the same disk the copy points at the source's stored original (zero bytes copied, refcount-
 * guarded like a deduplicated upload); on another disk the original is copied to a path no other
 * row there points at. The variants the target bucket also defines are copied (each staying on
 * the disk it was written to); the ones it lacks are generated. The target bucket's acceptance and
 * single-file rules apply.
 *
 * The new row is created inside a transaction; the copied files are written first so a rollback
 * leaves at most orphaned bytes (cleaned up by `media:clean`) rather than a row with no files.
 */
final class CopyMediaAction
{
    public function __construct(
        private readonly DiskResolver $diskResolver,
        private readonly FileTransfer $fileTransfer,
        private readonly StoredFiles $files,
        private readonly BucketGuard $guard,
        private readonly ReconcileVariantsAction $reconcileVariants,
    ) {}

    /**
     * @throws FileUnacceptableForBucket when the target bucket does not accept the media
     * @throws FileCannotBeWritten when the target disk refuses the original (no row is created)
     */
    public function execute(
        Media $media,
        HasMedia|Model|null $toModel = null,
        string $bucket = 'default',
        ?string $disk = null,
    ): Media {
        $this->guard->ensureSavedOwner($toModel);

        $targetDisk = $disk ?? $media->disk;
        $this->diskResolver->ensureDiskExists($targetDisk);

        $targetBucket = $this->guard->bucketFor($toModel, $bucket);
        $this->guard->ensureAcceptsMedia($targetBucket, $bucket, $media);

        $copy = $this->replicate($media, $toModel, $bucket, $targetDisk);

        $this->copyFiles($media, $copy);

        DB::transaction(static function () use ($copy): void {
            $copy->save();
        });

        if ($toModel instanceof Model) {
            $this->guard->enforceSingleFile($targetBucket, $toModel, $bucket, $copy);
        }

        event(new MediaHasBeenAdded($copy));

        $this->reconcileVariants->execute($copy);

        return $copy;
    }

    private function replicate(Media $media, HasMedia|Model|null $toModel, string $bucket, string $targetDisk): Media
    {
        $copy = $media->replicate(['uuid', 'draft_token', 'draft_expires_at']);
        $copy->uuid = (string) Str::uuid();
        $copy->bucket_name = $bucket;
        $copy->disk = $targetDisk;
        $copy->draft_token = null;
        $copy->draft_expires_at = null;

        // The original moves disk but variants keep their own disk, so re-base variants_disk.
        if ($media->variants_disk === null && $targetDisk !== $media->disk) {
            $copy->variants_disk = $media->disk;
        }

        if ($toModel instanceof Model) {
            $copy->model_type = $toModel->getMorphClass();
            $copy->model_id = $toModel->getKey();
        } else {
            $copy->model_type = null;
            $copy->model_id = null;
        }

        $copy->order_column = $this->nextOrderColumn($copy);

        // replicate() carries the source's loaded owner; the copy's variants follow ITS owner.
        $copy->setRelation('model', $toModel instanceof Model ? $toModel : null);

        return $copy;
    }

    private function copyFiles(Media $media, Media $copy): void
    {
        if ($copy->disk === $media->disk) {
            // Same disk: share the stored original, exactly like a deduplicated upload.
            $this->files->pin($media);
            $copy->path = $media->getPath();
        } else {
            $copy->path = $this->files->freePath($copy, $copy->disk, $media->getPath());

            // Before the row exists: a refused write leaves no copy pointing at a missing file.
            $this->fileTransfer->copyOrFail($media->disk, $media->getPath(), $copy->disk, $copy->path, $copy->visibility);
        }

        $keep = $this->variantNamesDefinedFor($copy);

        foreach ($media->generatedVariants() as $name => $variant) {
            if (! in_array($name, $keep, true)) {
                $copy->forgetGeneratedVariant($name);

                continue;
            }

            // Same file name, same disk — under the copy's own variants directory. One that fails
            // to copy is forgotten, so the reconcile renders it afresh instead of recording a gap.
            $copied = $this->fileTransfer->copy(
                $variant->disk,
                $media->getPath($name),
                $variant->disk,
                $copy->getPath($name),
                $copy->visibility,
            );

            if (! $copied) {
                $copy->forgetGeneratedVariant($name);
            }
        }
    }

    /** @return list<string> */
    private function variantNamesDefinedFor(Media $copy): array
    {
        return array_map(static fn ($variant): string => $variant->name, $copy->isImage() ? $copy->resolveVariants() : []);
    }

    private function nextOrderColumn(Media $copy): int
    {
        $query = MediaModel::query()->where('bucket_name', $copy->bucket_name);

        if ($copy->model_type !== null) {
            $query->where('model_type', $copy->model_type)->where('model_id', $copy->model_id);
        } else {
            $query->whereNull('model_type')->whereNull('model_id');
        }

        return (int) $query->max('order_column') + 1;
    }
}
