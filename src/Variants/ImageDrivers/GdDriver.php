<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Variants\ImageDrivers;

use GdImage;
use RoundlyConsulting\MediaLibrary\Contracts\ImageDriver;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\RgbaImage;
use RoundlyConsulting\MediaLibrary\Exceptions\InvalidVariant;
use RoundlyConsulting\MediaLibrary\Support\ExifOrientation;
use RoundlyConsulting\MediaLibrary\Support\ImageDecodeGuard;

/**
 * GD-backed fallback image driver, used when ext-imagick is unavailable.
 *
 * Supports the formats compiled into the GD build ({@see supportsFormat()}); `sharpen()` is a
 * no-op on GD. `avif` is only available where the GD build was compiled with AVIF support.
 */
final class GdDriver implements ImageDriver
{
    private GdImage $image;

    private string $format = 'jpg';

    private int $quality = 75;

    private string $background = '#ffffff';

    public function load(string $path): ImageDriver
    {
        // Type and pixel count are checked from the header first: GD would decode anything.
        ImageDecodeGuard::inspect($path);

        $contents = (string) file_get_contents($path);
        // Suppress GD's warning on undecodable data so it surfaces as our typed exception rather
        // than the host's error handler converting the warning into an ErrorException.
        $image = @imagecreatefromstring($contents);

        if ($image === false) {
            throw InvalidVariant::unknownName('original');
        }

        $image = $this->orient($image, ExifOrientation::fromBytes($contents));

        imagealphablending($image, false);
        imagesavealpha($image, true);

        $this->image = $image;

        return $this;
    }

    public function width(): int
    {
        return imagesx($this->image);
    }

    public function height(): int
    {
        return imagesy($this->image);
    }

    public function fit(string $mode, ?int $width, ?int $height): ImageDriver
    {
        $sourceWidth = $this->width();
        $sourceHeight = $this->height();

        $targetWidth = $width ?? $sourceWidth;
        $targetHeight = $height ?? $sourceHeight;

        match ($mode) {
            'stretch' => $this->drawResized($targetWidth, $targetHeight, 0, 0, $sourceWidth, $sourceHeight),
            'contain' => $this->containFit($targetWidth, $targetHeight, $sourceWidth, $sourceHeight),
            'cover', 'crop' => $this->cropFit($targetWidth, $targetHeight, $sourceWidth, $sourceHeight),
            'fill' => $this->fillFit($targetWidth, $targetHeight, $sourceWidth, $sourceHeight),
        };

        return $this;
    }

    public function resize(?int $width, ?int $height): ImageDriver
    {
        [$targetWidth, $targetHeight] = $this->scaleKeepingRatio(
            $width,
            $height,
            $this->width(),
            $this->height(),
        );

        $this->drawResized($targetWidth, $targetHeight, 0, 0, $this->width(), $this->height());

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
        // GD has no first-class unsharp mask; sharpening is intentionally a no-op here.
        return $this;
    }

    public function encode(): string
    {
        ob_start();
        $this->output();

        return (string) ob_get_clean();
    }

    public function save(string $path): void
    {
        file_put_contents($path, $this->encode());
    }

    public function rgbaPixels(int $maxSize): RgbaImage
    {
        [$width, $height] = $this->scaleKeepingRatio(
            min($maxSize, $this->width()),
            min($maxSize, $this->height()),
            $this->width(),
            $this->height(),
        );

        $canvas = $this->canvas($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagecopyresampled($canvas, $this->image, 0, 0, 0, 0, $width, $height, $this->width(), $this->height());

        $pixels = [];

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $rgba = imagecolorat($canvas, $x, $y);
                // GD packs alpha as 0..127 (0 = opaque); convert to the 0..255, 255 = opaque scale.
                $alpha = ($rgba >> 24) & 0x7F;
                $pixels[] = ($rgba >> 16) & 0xFF;
                $pixels[] = ($rgba >> 8) & 0xFF;
                $pixels[] = $rgba & 0xFF;
                $pixels[] = (int) round(255 - $alpha * 255 / 127);
            }
        }

        return new RgbaImage($width, $height, $pixels);
    }

    public function supportsFormat(string $format): bool
    {
        $info = gd_info();

        return match ($format === 'jpeg' ? 'jpg' : $format) {
            'jpg' => ($info['JPEG Support'] ?? false) === true,
            'png' => ($info['PNG Support'] ?? false) === true,
            'webp' => ($info['WebP Support'] ?? false) === true,
            'gif' => ($info['GIF Create Support'] ?? false) === true,
            'avif' => ($info['AVIF Support'] ?? false) === true,
            default => false,
        };
    }

    public function name(): string
    {
        return 'gd';
    }

    /**
     * Turn a camera's stored pixels upright per its EXIF orientation. GD drops all metadata on
     * decode, so the tag is read from the raw bytes and nothing is left to re-apply on output.
     * `imagerotate()` angles run counter-clockwise: 270 is a quarter turn clockwise.
     */
    private function orient(GdImage $image, int $orientation): GdImage
    {
        return match ($orientation) {
            2 => $this->flipped($image, IMG_FLIP_HORIZONTAL),
            3 => $this->rotated($image, 180),
            4 => $this->flipped($image, IMG_FLIP_VERTICAL),
            5 => $this->flipped($this->rotated($image, 270), IMG_FLIP_HORIZONTAL),
            6 => $this->rotated($image, 270),
            7 => $this->flipped($this->rotated($image, 90), IMG_FLIP_HORIZONTAL),
            8 => $this->rotated($image, 90),
            default => $image,
        };
    }

    private function rotated(GdImage $image, int $degrees): GdImage
    {
        $rotated = imagerotate($image, $degrees, 0);

        return $rotated === false ? $image : $rotated;
    }

    private function flipped(GdImage $image, int $mode): GdImage
    {
        imageflip($image, $mode);

        return $image;
    }

    private function containFit(int $width, int $height, int $sourceWidth, int $sourceHeight): void
    {
        [$targetWidth, $targetHeight] = $this->scaleKeepingRatio($width, $height, $sourceWidth, $sourceHeight);

        $this->drawResized($targetWidth, $targetHeight, 0, 0, $sourceWidth, $sourceHeight);
    }

    private function cropFit(int $width, int $height, int $sourceWidth, int $sourceHeight): void
    {
        $scale = max($width / $sourceWidth, $height / $sourceHeight);
        $scaledWidth = (int) ceil($sourceWidth * $scale);
        $scaledHeight = (int) ceil($sourceHeight * $scale);

        $offsetX = (int) (($scaledWidth - $width) / 2 / $scale);
        $offsetY = (int) (($scaledHeight - $height) / 2 / $scale);

        $cropWidth = (int) ($width / $scale);
        $cropHeight = (int) ($height / $scale);

        $this->drawResized($width, $height, $offsetX, $offsetY, $cropWidth, $cropHeight);
    }

    private function fillFit(int $width, int $height, int $sourceWidth, int $sourceHeight): void
    {
        [$scaledWidth, $scaledHeight] = $this->scaleKeepingRatio($width, $height, $sourceWidth, $sourceHeight);

        $canvas = $this->canvas($width, $height);
        $this->fillBackground($canvas, $width, $height);

        imagecopyresampled(
            $canvas,
            $this->image,
            (int) (($width - $scaledWidth) / 2),
            (int) (($height - $scaledHeight) / 2),
            0,
            0,
            $scaledWidth,
            $scaledHeight,
            $sourceWidth,
            $sourceHeight,
        );

        $this->image = $canvas;
    }

    private function drawResized(int $width, int $height, int $srcX, int $srcY, int $srcWidth, int $srcHeight): void
    {
        $canvas = $this->canvas($width, $height);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        imagecopyresampled(
            $canvas,
            $this->image,
            0,
            0,
            $srcX,
            $srcY,
            $width,
            $height,
            $srcWidth,
            $srcHeight,
        );

        $this->image = $canvas;
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function scaleKeepingRatio(?int $width, ?int $height, int $sourceWidth, int $sourceHeight): array
    {
        if ($width === null) {
            if ($height === null) {
                return [$sourceWidth, $sourceHeight];
            }

            $ratio = $height / $sourceHeight;

            return [max(1, (int) round($sourceWidth * $ratio)), $height];
        }

        if ($height === null) {
            $ratio = $width / $sourceWidth;

            return [$width, max(1, (int) round($sourceHeight * $ratio))];
        }

        // Both dimensions set — scale to fit within the box, preserving ratio.
        $ratio = min($width / $sourceWidth, $height / $sourceHeight);

        return [max(1, (int) round($sourceWidth * $ratio)), max(1, (int) round($sourceHeight * $ratio))];
    }

    private function output(): void
    {
        match ($this->format) {
            'png' => $this->outputPng(),
            'webp' => imagewebp($this->image, null, $this->quality),
            'gif' => imagegif($this->image),
            'avif' => imageavif($this->image, null, $this->quality),
            default => $this->outputJpeg(),
        };
    }

    private function outputJpeg(): void
    {
        $width = $this->width();
        $height = $this->height();
        $canvas = $this->canvas($width, $height);
        $this->fillBackground($canvas, $width, $height);
        imagecopy($canvas, $this->image, 0, 0, 0, 0, $width, $height);

        imagejpeg($canvas, null, $this->quality);
    }

    private function outputPng(): void
    {
        // PNG quality is 0..9 (compression), inverse of the 1..100 quality scale.
        $compression = (int) round((100 - $this->quality) / 100 * 9);

        imagepng($this->image, null, $compression);
    }

    private function canvas(int $width, int $height): GdImage
    {
        $canvas = imagecreatetruecolor(max(1, $width), max(1, $height));

        if ($canvas === false) {
            throw InvalidVariant::unknownName('canvas');
        }

        return $canvas;
    }

    private function fillBackground(GdImage $canvas, int $width, int $height): void
    {
        [$r, $g, $b] = $this->backgroundRgb();
        $fill = imagecolorallocate($canvas, $r, $g, $b);
        imagefilledrectangle($canvas, 0, 0, $width, $height, $fill === false ? 0 : $fill);
    }

    /**
     * @return array{0: int<0, 255>, 1: int<0, 255>, 2: int<0, 255>}
     */
    private function backgroundRgb(): array
    {
        $hex = ltrim($this->background, '#');

        if (strlen($hex) !== 6) {
            return [255, 255, 255];
        }

        return [
            $this->channel(substr($hex, 0, 2)),
            $this->channel(substr($hex, 2, 2)),
            $this->channel(substr($hex, 4, 2)),
        ];
    }

    /** @return int<0, 255> */
    private function channel(string $hex): int
    {
        $value = (int) hexdec($hex);

        return max(0, min(255, $value));
    }
}
