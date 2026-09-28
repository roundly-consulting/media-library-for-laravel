<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Contracts\FileNamer;
use RoundlyConsulting\MediaLibrary\Contracts\ImageDriver;
use RoundlyConsulting\MediaLibrary\Contracts\PathGenerator;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\ManipulationSet;
use RoundlyConsulting\MediaLibrary\Events\VariantHasBeenGenerated;
use RoundlyConsulting\MediaLibrary\Events\VariantsHaveBeenGenerated;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\DiskResolver;
use RoundlyConsulting\MediaLibrary\Variants\Variant;

/**
 * Generates image derivatives ("variants") for a {@see Media} through the active
 * {@see ImageDriver}, writing each to the resolved variants disk under
 * `{uuid}/variants/{name}.{ext}` and recording `generated_variants[name] = true`.
 *
 * @internal building block — renders exactly the definitions it is handed. Hosts regenerate with
 *           `MediaLibrary::variants($media)->regenerate()` ({@see RegenerateVariantsAction}).
 */
final class GenerateVariantsAction
{
    public function __construct(
        private readonly DiskResolver $diskResolver,
        private readonly PathGenerator $pathGenerator,
        private readonly FileNamer $fileNamer,
    ) {}

    /**
     * @param  list<Variant>  $variants
     */
    public function execute(Media $media, array $variants): Media
    {
        if ($variants === []) {
            return $media;
        }

        $driver = app(ImageDriver::class);

        $generated = [];

        foreach ($variants as $variant) {
            $this->generateOne($media, $variant, $driver);
            $generated[] = $variant->name;
        }

        $media->save();

        event(new VariantsHaveBeenGenerated($media, $generated));

        return $media;
    }

    private function generateOne(Media $media, Variant $variant, ImageDriver $driver): void
    {
        $manipulations = $variant->resolve($driver, (string) $media->extension);

        $bytes = $this->render($media, $manipulations, $driver);

        $disk = $this->diskResolver->resolveVariantDisk(
            $variant->disk(),
            $media->variants_disk,
            $media->disk,
        );

        $path = $this->pathGenerator->getPathForVariants($media)
            .$this->fileNamer->variantFileName($variant->name, $manipulations->format);

        Storage::disk($disk)->put($path, $bytes, ['visibility' => $media->visibility]);

        $variants = $media->generated_variants ?? [];
        $variants[$variant->name] = true;
        $media->generated_variants = $variants;

        event(new VariantHasBeenGenerated($media, $variant->name));
    }

    private function render(Media $media, ManipulationSet $manipulations, ImageDriver $driver): string
    {
        $source = $this->localCopyOfOriginal($media);

        try {
            $driver->load($source)
                ->fit($manipulations->fit, $manipulations->width, $manipulations->height)
                ->sharpen($manipulations->sharpen)
                ->background($manipulations->background)
                ->format($manipulations->format)
                ->quality($manipulations->quality);

            return $driver->encode();
        } finally {
            if (is_file($source)) {
                @unlink($source);
            }
        }
    }

    private function localCopyOfOriginal(Media $media): string
    {
        $stream = Storage::disk($media->disk)->readStream($media->getPath());

        $temp = (string) tempnam(sys_get_temp_dir(), 'media_variant_');

        $target = fopen($temp, 'wb');

        if (is_resource($stream) && $target !== false) {
            stream_copy_to_stream($stream, $target);
        }

        if (is_resource($stream)) {
            fclose($stream);
        }

        if ($target !== false) {
            fclose($target);
        }

        return $temp;
    }
}
