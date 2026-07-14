<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\MediaLibrary\Events\MediaHasBeenAdded;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\DiskResolver;
use RoundlyConsulting\MediaLibrary\Support\FileTransfer;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;

/**
 * Duplicates a {@see Media} — its original and generated variants — into a new row with a fresh
 * uuid, optionally onto a different disk and/or under a different owning model and bucket. The
 * source files are preserved.
 *
 * The new row is created inside a transaction; the copied files are written first so a rollback
 * leaves at most orphaned bytes (cleaned up by `media:clean`) rather than a row with no files.
 */
final class CopyMediaAction
{
    public function __construct(
        private readonly DiskResolver $diskResolver,
        private readonly FileTransfer $fileTransfer,
    ) {}

    public function execute(
        Media $media,
        HasMedia|Model|null $toModel = null,
        string $bucket = 'default',
        ?string $disk = null,
    ): Media {
        $targetDisk = $disk ?? $media->disk;
        $this->diskResolver->ensureDiskExists($targetDisk);

        $copy = $this->replicate($media, $toModel, $bucket, $targetDisk);

        $this->copyFiles($media, $copy);

        DB::transaction(static function () use ($copy): void {
            $copy->save();
        });

        event(new MediaHasBeenAdded($copy));

        return $copy;
    }

    private function replicate(Media $media, HasMedia|Model|null $toModel, string $bucket, string $targetDisk): Media
    {
        $copy = $media->replicate(['uuid']);
        $copy->uuid = (string) Str::uuid();
        $copy->bucket_name = $bucket;
        $copy->disk = $targetDisk;

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

        return $copy;
    }

    private function copyFiles(Media $media, Media $copy): void
    {
        $this->fileTransfer->copy(
            $media->disk,
            $media->getPath(),
            $copy->disk,
            $copy->getPath(),
            $copy->visibility,
        );

        $variantsFrom = $media->variants_disk ?? $media->disk;
        $variantsTo = $copy->variants_disk ?? $copy->disk;

        foreach (array_keys($media->generated_variants ?? []) as $name) {
            $name = (string) $name;

            $sourcePath = $media->getPath($name);

            // Preserve the stored variant filename rather than re-deriving it from the (possibly
            // different) target bucket, so the copied file keeps the bytes it points at.
            $targetPath = $copy->getPathForVariantsDirectory().basename($sourcePath);

            $this->fileTransfer->copy(
                $variantsFrom,
                $sourcePath,
                $variantsTo,
                $targetPath,
                $copy->visibility,
            );
        }
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
