<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Placeholders;

use Closure;
use Imagick;
use ImagickPixel;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\RgbaImage;

/**
 * Server-side decode of a stored LQIP placeholder into a tiny PNG `data:` URI.
 *
 * ThumbHash is preferred (it carries aspect ratio and alpha); Blurhash is used as a fallback.
 * The PNG is drawn with GD, else with Imagick — either extension is enough — and with neither
 * there is no data URI (null) rather than an error.
 */
final class PlaceholderDataUri
{
    /** @var Closure(string): bool */
    private readonly Closure $hasExtension;

    /**
     * @param  (Closure(string): bool)|null  $hasExtension  replaces the `gd`/`imagick` probe (testing seam)
     */
    public function __construct(
        private readonly ThumbHashEncoder $thumbHash,
        private readonly BlurHashEncoder $blurHash,
        ?Closure $hasExtension = null,
    ) {
        $this->hasExtension = $hasExtension ?? static fn (string $extension): bool => match ($extension) {
            // Functions, not the extension: a host can disable GD's functions with it loaded.
            'gd' => function_exists('imagecreatetruecolor') && function_exists('imagepng'),
            default => extension_loaded($extension),
        };
    }

    /**
     * @param  array<string, string>  $placeholders
     */
    public function fromPlaceholders(array $placeholders): ?string
    {
        $thumb = $placeholders['thumbhash'] ?? null;

        if (is_string($thumb) && $thumb !== '') {
            return $this->render($this->thumbHash->decodeToRgba($thumb));
        }

        $blur = $placeholders['blurhash'] ?? null;

        if (is_string($blur) && $blur !== '') {
            return $this->render($this->blurHash->decodeToRgba($blur, 32, 32));
        }

        return null;
    }

    private function render(RgbaImage $image): ?string
    {
        if (($this->hasExtension)('gd')) {
            return $this->renderWithGd($image);
        }

        if (($this->hasExtension)('imagick')) {
            return $this->renderWithImagick($image);
        }

        return null;
    }

    private function renderWithImagick(RgbaImage $image): string
    {
        $width = max(1, $image->width);
        $height = max(1, $image->height);

        $canvas = new Imagick;
        $canvas->newImage($width, $height, new ImagickPixel('transparent'));
        $canvas->importImagePixels(0, 0, $width, $height, 'RGBA', Imagick::PIXEL_CHAR, $this->bytes($image, $width * $height * 4));
        $canvas->setImageFormat('png');

        $bytes = $canvas->getImageBlob();
        $canvas->clear();

        return 'data:image/png;base64,'.base64_encode($bytes);
    }

    /**
     * The raster's channels as bytes, padded to the canvas size.
     *
     * @return list<int>
     */
    private function bytes(RgbaImage $image, int $length): array
    {
        $bytes = [];

        for ($i = 0; $i < $length; $i++) {
            $bytes[] = $this->channel($image->pixels[$i] ?? ($i % 4 === 3 ? 255 : 0));
        }

        return $bytes;
    }

    private function renderWithGd(RgbaImage $image): ?string
    {
        $width = max(1, $image->width);
        $height = max(1, $image->height);

        $canvas = imagecreatetruecolor($width, $height);

        if ($canvas === false) {
            return null;
        }

        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $offset = ($y * $width + $x) * 4;
                // GD alpha is 0..127 (0 = opaque), inverse of the 0..255, 255 = opaque scale.
                $alpha = $this->alpha((255 - ($image->pixels[$offset + 3] ?? 255)) * 127 / 255);
                $color = imagecolorallocatealpha(
                    $canvas,
                    $this->channel($image->pixels[$offset] ?? 0),
                    $this->channel($image->pixels[$offset + 1] ?? 0),
                    $this->channel($image->pixels[$offset + 2] ?? 0),
                    $alpha,
                );

                imagesetpixel($canvas, $x, $y, $color === false ? 0 : $color);
            }
        }

        ob_start();
        imagepng($canvas);
        $bytes = (string) ob_get_clean();

        return 'data:image/png;base64,'.base64_encode($bytes);
    }

    /** @return int<0, 255> */
    private function channel(float|int $value): int
    {
        return max(0, min(255, (int) round($value)));
    }

    /** @return int<0, 127> */
    private function alpha(float|int $value): int
    {
        return max(0, min(127, (int) round($value)));
    }
}
