<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Variants\ImageDrivers;

use RoundlyConsulting\MediaLibrary\Contracts\ImageDriver;
use RoundlyConsulting\MediaLibrary\Exceptions\VariantDriverUnavailable;

/**
 * Selects the image driver from `config('media.image_driver')`, automatically falling back from
 * Imagick to GD when ext-imagick is absent. Throws {@see VariantDriverUnavailable} only when a
 * variant is actually requested and neither extension is loaded — so media with no variants
 * never needs an image extension.
 */
final class ImageDriverFactory
{
    /**
     * @param  (callable(string): bool)|null  $hasExtension  Override the extension probe (testing seam).
     */
    public static function make(?string $preferred = null, ?callable $hasExtension = null): ImageDriver
    {
        $hasExtension ??= 'extension_loaded';
        $preferred ??= self::configuredDriver();

        if ($preferred === 'gd' && $hasExtension('gd')) {
            return new GdDriver;
        }

        if ($hasExtension('imagick')) {
            return new ImagickDriver;
        }

        if ($hasExtension('gd')) {
            return new GdDriver;
        }

        throw VariantDriverUnavailable::noExtension();
    }

    private static function configuredDriver(): string
    {
        $driver = config('media.image_driver');

        return $driver === 'gd' ? 'gd' : 'imagick';
    }
}
