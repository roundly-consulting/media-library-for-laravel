<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Placeholders;

use RoundlyConsulting\MediaLibrary\Contracts\ImageDriver;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\RgbaImage;

/**
 * Computes the LQIP placeholder map ({thumbhash, blurhash}) for an image from a single
 * downscaled RGBA sample, honouring the per-type `config('media.placeholders')` toggles.
 */
final class PlaceholderGenerator
{
    /** Sample the original down to this longest side before encoding — bounds the cost. */
    private const SAMPLE_SIZE = 100;

    public function __construct(
        private readonly ThumbHashEncoder $thumbHash,
        private readonly BlurHashEncoder $blurHash,
    ) {}

    /**
     * @return array<string, string> the placeholder map (may be empty when both types are off)
     */
    public function forLocalImage(string $path, ImageDriver $driver): array
    {
        $wantsThumbHash = config('media.placeholders.thumbhash') !== false;
        $wantsBlurHash = config('media.placeholders.blurhash') !== false;

        if (! $wantsThumbHash && ! $wantsBlurHash) {
            return [];
        }

        $image = $driver->load($path)->rgbaPixels(self::SAMPLE_SIZE);

        return $this->forPixels($image, $wantsThumbHash, $wantsBlurHash);
    }

    /**
     * @return array<string, string>
     */
    public function forPixels(RgbaImage $image, bool $wantsThumbHash = true, bool $wantsBlurHash = true): array
    {
        $placeholders = [];

        if ($wantsThumbHash) {
            $placeholders['thumbhash'] = $this->thumbHash->encode($image);
        }

        if ($wantsBlurHash) {
            $placeholders['blurhash'] = $this->blurHash->encode($image);
        }

        return $placeholders;
    }
}
