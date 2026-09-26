<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Variants\ImageDrivers;

use Imagick;
use ImagickPixel;
use RoundlyConsulting\MediaLibrary\Contracts\ImageDriver;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\RgbaImage;

/**
 * Imagick-backed image driver — the default whenever ext-imagick is loaded.
 *
 * Handles the full variant format set (jpg/png/webp/avif/gif) subject to the linked
 * ImageMagick build's delegate support, which {@see supportsFormat()} reflects.
 */
final class ImagickDriver implements ImageDriver
{
    private Imagick $image;

    private string $format = 'jpg';

    private int $quality = 75;

    private string $background = '#ffffff';

    public function load(string $path): ImageDriver
    {
        $this->image = new Imagick;
        $this->image->readImage($path);
        $this->autoOrient();
        $this->format = strtolower($this->image->getImageFormat());

        return $this;
    }

    public function width(): int
    {
        return $this->image->getImageWidth();
    }

    public function height(): int
    {
        return $this->image->getImageHeight();
    }

    public function fit(string $mode, ?int $width, ?int $height): ImageDriver
    {
        $sourceWidth = $this->width();
        $sourceHeight = $this->height();

        $targetWidth = $width ?? $sourceWidth;
        $targetHeight = $height ?? $sourceHeight;

        match ($mode) {
            'stretch' => $this->image->resizeImage($targetWidth, $targetHeight, Imagick::FILTER_LANCZOS, 1),
            'contain' => $this->image->thumbnailImage($targetWidth, $targetHeight, true),
            'cover', 'crop' => $this->cropFit($targetWidth, $targetHeight),
            'fill' => $this->fillFit($targetWidth, $targetHeight),
        };

        return $this;
    }

    public function resize(?int $width, ?int $height): ImageDriver
    {
        $this->image->thumbnailImage($width ?? 0, $height ?? 0, false);

        return $this;
    }

    public function format(string $format): ImageDriver
    {
        $this->format = $format === 'jpeg' ? 'jpg' : $format;

        return $this;
    }

    public function quality(int $quality): ImageDriver
    {
        $this->quality = $quality;

        return $this;
    }

    public function background(string $color): ImageDriver
    {
        $this->background = $color;

        return $this;
    }

    public function sharpen(int $amount): ImageDriver
    {
        if ($amount > 0) {
            $this->image->sharpenImage(0, $amount / 10);
        }

        return $this;
    }

    public function encode(): string
    {
        $this->applyFormat();

        return $this->image->getImageBlob();
    }

    public function save(string $path): void
    {
        $this->applyFormat();
        $this->image->writeImage($path);
    }

    public function rgbaPixels(int $maxSize): RgbaImage
    {
        $clone = clone $this->image;
        $clone->setImageColorspace(Imagick::COLORSPACE_SRGB);

        $sourceWidth = $clone->getImageWidth();
        $sourceHeight = $clone->getImageHeight();

        $scale = min(1.0, $maxSize / max($sourceWidth, $sourceHeight));
        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));

        $clone->thumbnailImage($width, $height, false);

        /** @var list<int> $pixels */
        $pixels = $clone->exportImagePixels(0, 0, $width, $height, 'RGBA', Imagick::PIXEL_CHAR);

        $clone->clear();

        return new RgbaImage($width, $height, $pixels);
    }

    public function supportsFormat(string $format): bool
    {
        $format = $format === 'jpg' ? 'jpeg' : $format;

        return Imagick::queryFormats(strtoupper($format)) !== [];
    }

    public function name(): string
    {
        return 'imagick';
    }

    /**
     * Turn the pixels upright per the EXIF orientation a camera recorded, THEN mark the image
     * upright. Clearing the flag without moving the pixels is what left phone photos sideways.
     * Done by hand rather than `autoOrient()` so every imagick build behaves the same.
     */
    private function autoOrient(): void
    {
        $background = new ImagickPixel('none');

        match ($this->image->getImageOrientation()) {
            Imagick::ORIENTATION_TOPRIGHT => $this->image->flopImage(),
            Imagick::ORIENTATION_BOTTOMRIGHT => $this->image->rotateImage($background, 180),
            Imagick::ORIENTATION_BOTTOMLEFT => $this->image->flipImage(),
            Imagick::ORIENTATION_LEFTTOP => $this->image->transposeImage(),
            Imagick::ORIENTATION_RIGHTTOP => $this->image->rotateImage($background, 90),
            Imagick::ORIENTATION_RIGHTBOTTOM => $this->image->transverseImage(),
            Imagick::ORIENTATION_LEFTBOTTOM => $this->image->rotateImage($background, 270),
            default => true,
        };

        $this->image->setImagePage(0, 0, 0, 0);
        $this->image->setImageOrientation(Imagick::ORIENTATION_TOPLEFT);
    }

    private function cropFit(int $width, int $height): void
    {
        $this->image->cropThumbnailImage($width, $height);
    }

    private function fillFit(int $width, int $height): void
    {
        $this->image->thumbnailImage($width, $height, true);
        $this->image->setImageBackgroundColor(new ImagickPixel($this->background));
        $this->image->extentImage(
            $width,
            $height,
            (int) (($this->image->getImageWidth() - $width) / 2),
            (int) (($this->image->getImageHeight() - $height) / 2),
        );
    }

    private function applyFormat(): void
    {
        $writeFormat = $this->format === 'jpg' ? 'jpeg' : $this->format;
        $this->image->setImageFormat($writeFormat);
        $this->image->setImageCompressionQuality($this->quality);

        if ($writeFormat === 'jpeg') {
            $this->image->setImageBackgroundColor(new ImagickPixel($this->background));
            $flattened = $this->image->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $flattened->setImageFormat('jpeg');
            $flattened->setImageCompressionQuality($this->quality);
            $this->image = $flattened;
        }
    }
}
