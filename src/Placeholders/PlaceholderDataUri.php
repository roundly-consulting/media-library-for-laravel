<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Placeholders;

use RoundlyConsulting\MediaLibrary\DataTransferObjects\RgbaImage;

/**
 * Server-side decode of a stored LQIP placeholder into a tiny PNG `data:` URI.
 *
 * ThumbHash is preferred (it carries aspect ratio and alpha); Blurhash is used as a fallback.
 * Rendering uses ext-gd only — no third-party dependency.
 */
final class PlaceholderDataUri
{
    public function __construct(
        private readonly ThumbHashEncoder $thumbHash,
        private readonly BlurHashEncoder $blurHash,
    ) {}

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
