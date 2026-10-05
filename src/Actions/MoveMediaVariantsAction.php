<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Events\MediaHasBeenMoved;
use RoundlyConsulting\MediaLibrary\Exceptions\FileCannotBeWritten;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\DiskResolver;
use RoundlyConsulting\MediaLibrary\Support\FileTransfer;
use Throwable;

/**
 * Relocates only a {@see Media}'s generated variant files onto another disk — every variant,
 * wherever it was written (a per-variant `storeOnDisk()` included); the original, the owner and
 * the bucket stay put. Variants are always per-row, so no refcount guard applies.
 *
 * Every variant is copied first; only once all copies landed is the row re-pointed, and only then
 * are the source files removed. A refused write throws {@see FileCannotBeWritten} with the row
 * and every source file untouched.
 *
 * Storing the variants on the original's own disk records `variants_disk` as null again.
 */
final class MoveMediaVariantsAction
{
    public function __construct(
        private readonly DiskResolver $diskResolver,
        private readonly FileTransfer $fileTransfer,
    ) {}

    public function execute(Media $media, string $disk): Media
    {
        $this->diskResolver->ensureDiskExists($disk);

        $moved = [];
        $sources = [];
        $written = [];

        try {
            foreach ($media->generatedVariants() as $name => $variant) {
                if ($variant->disk === $disk) {
                    continue;
                }

                $path = $media->getPath($name);

                // A variant whose file is already gone has nothing to carry; its record still follows.
                if (Storage::disk($variant->disk)->exists($path)) {
                    $this->fileTransfer->copyOrFail($variant->disk, $path, $disk, $path, $media->visibility);
                    $written[] = $path;
                    $sources[] = [$variant->disk, $path];
                }

                $moved[$name] = $variant->onDisk($disk);
            }
        } catch (Throwable $exception) {
            foreach ($written as $path) {
                $this->fileTransfer->delete($disk, $path);
            }

            throw $exception;
        }

        DB::transaction(static function () use ($media, $disk, $moved): void {
            foreach ($moved as $name => $variant) {
                $media->recordGeneratedVariant($name, $variant);
            }

            $media->variants_disk = $disk === $media->disk ? null : $disk;
            $media->save();
        });

        foreach ($sources as [$sourceDisk, $path]) {
            $this->fileTransfer->delete($sourceDisk, $path);
        }

        event(new MediaHasBeenMoved($media));

        return $media;
    }
}
