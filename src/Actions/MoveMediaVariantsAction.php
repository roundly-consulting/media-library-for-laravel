<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\MediaLibrary\Events\MediaHasBeenMoved;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\DiskResolver;
use RoundlyConsulting\MediaLibrary\Support\FileTransfer;

/**
 * Relocates only a {@see Media}'s generated variant files onto another disk — every variant,
 * wherever it was written (a per-variant `storeOnDisk()` included); the original, the owner and
 * the bucket stay put. Variants are always per-row, so no refcount guard applies.
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

        foreach ($media->generatedVariants() as $name => $variant) {
            if ($variant->disk === $disk) {
                continue;
            }

            $path = $media->getPath($name);

            $this->fileTransfer->move($variant->disk, $path, $disk, $path, $media->visibility);
            $media->recordGeneratedVariant($name, $variant->onDisk($disk));
        }

        DB::transaction(static function () use ($media, $disk): void {
            $media->variants_disk = $disk === $media->disk ? null : $disk;
            $media->save();
        });

        event(new MediaHasBeenMoved($media));

        return $media;
    }
}
