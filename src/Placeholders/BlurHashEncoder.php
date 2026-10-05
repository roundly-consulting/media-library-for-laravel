<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Placeholders;

use RoundlyConsulting\MediaLibrary\DataTransferObjects\RgbaImage;

/**
 * A pure-PHP, dependency-free port of the Blurhash algorithm (Wolt's reference spec).
 *
 * Encodes an {@see RgbaImage} into the compact Blurhash string used as a blurred placeholder,
 * and decodes a Blurhash back into a tiny RGBA raster for a server-side `data:` URI. The output
 * is byte-for-byte compatible with the published reference vectors.
 *
 * @see https://github.com/woltapp/blurhash/blob/master/Algorithm.md
 */
final class BlurHashEncoder
{
    private const ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz#$%*+,-.:;=?@[]^_{|}~';

    public function encode(RgbaImage $image, int $componentsX = 4, int $componentsY = 3): string
    {
        $componentsX = max(1, min(9, $componentsX));
        $componentsY = max(1, min(9, $componentsY));

        $width = $image->width;
        $height = $image->height;
        $pixels = $image->pixels;

        $factors = [];
        $maxAc = 0.0;

        for ($y = 0; $y < $componentsY; $y++) {
            for ($x = 0; $x < $componentsX; $x++) {
                $normalisation = $x === 0 && $y === 0 ? 1.0 : 2.0;
                $r = 0.0;
                $g = 0.0;
                $b = 0.0;

                for ($py = 0; $py < $height; $py++) {
                    for ($px = 0; $px < $width; $px++) {
                        $basis = $normalisation
                            * cos(M_PI * $x * $px / $width)
                            * cos(M_PI * $y * $py / $height);

                        $offset = ($py * $width + $px) * 4;
                        $r += $basis * $this->sRgbToLinear($pixels[$offset]);
                        $g += $basis * $this->sRgbToLinear($pixels[$offset + 1]);
                        $b += $basis * $this->sRgbToLinear($pixels[$offset + 2]);
                    }
                }

                $scale = 1.0 / ($width * $height);
                $factor = [$r * $scale, $g * $scale, $b * $scale];
                $factors[] = $factor;

                if ($x !== 0 || $y !== 0) {
                    $maxAc = max($maxAc, abs($factor[0]), abs($factor[1]), abs($factor[2]));
                }
            }
        }

        $dc = $factors[0];
        $acCount = count($factors) - 1;

        $hash = $this->encodeBase83(($componentsX - 1) + ($componentsY - 1) * 9, 1);

        if ($acCount > 0) {
            $quantisedMax = max(0, min(82, (int) floor($maxAc * 166 - 0.5)));
            $maximumValue = ($quantisedMax + 1) / 166;
            $hash .= $this->encodeBase83($quantisedMax, 1);
        } else {
            $maximumValue = 1.0;
            $hash .= $this->encodeBase83(0, 1);
        }

        $hash .= $this->encodeBase83($this->encodeDc($dc), 4);

        for ($i = 1; $i <= $acCount; $i++) {
            $hash .= $this->encodeBase83($this->encodeAc($factors[$i], $maximumValue), 2);
        }

        return $hash;
    }

    public function decodeToRgba(string $hash, int $width, int $height, float $punch = 1.0): RgbaImage
    {
        $sizeFlag = $this->decodeBase83(substr($hash, 0, 1));
        $componentsX = ($sizeFlag % 9) + 1;
        $componentsY = (int) floor($sizeFlag / 9) + 1;

        $quantisedMax = $this->decodeBase83(substr($hash, 1, 1));
        $maximumValue = (($quantisedMax + 1) / 166) * $punch;

        $count = $componentsX * $componentsY;
        $colors = [];

        for ($i = 0; $i < $count; $i++) {
            if ($i === 0) {
                $colors[] = $this->decodeDc($this->decodeBase83(substr($hash, 2, 4)));
            } else {
                // After the size flag, the max value and the 4-character DC: AC i sits at 4 + 2i.
                $value = $this->decodeBase83(substr($hash, 4 + $i * 2, 2));
                $colors[] = $this->decodeAc($value, $maximumValue);
            }
        }

        $pixels = [];

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $r = 0.0;
                $g = 0.0;
                $b = 0.0;

                for ($cy = 0; $cy < $componentsY; $cy++) {
                    for ($cx = 0; $cx < $componentsX; $cx++) {
                        $basis = cos(M_PI * $x * $cx / $width) * cos(M_PI * $y * $cy / $height);
                        $color = $colors[$cy * $componentsX + $cx];
                        $r += $color[0] * $basis;
                        $g += $color[1] * $basis;
                        $b += $color[2] * $basis;
                    }
                }

                $pixels[] = $this->linearToSRgb($r);
                $pixels[] = $this->linearToSRgb($g);
                $pixels[] = $this->linearToSRgb($b);
                $pixels[] = 255;
            }
        }

        return new RgbaImage($width, $height, $pixels);
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $value
     */
    private function encodeDc(array $value): int
    {
        $r = $this->linearToSRgb($value[0]);
        $g = $this->linearToSRgb($value[1]);
        $b = $this->linearToSRgb($value[2]);

        return ($r << 16) + ($g << 8) + $b;
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $value
     */
    private function encodeAc(array $value, float $maximumValue): int
    {
        $r = $this->quantiseAc($value[0], $maximumValue);
        $g = $this->quantiseAc($value[1], $maximumValue);
        $b = $this->quantiseAc($value[2], $maximumValue);

        return $r * 19 * 19 + $g * 19 + $b;
    }

    private function quantiseAc(float $component, float $maximumValue): int
    {
        $value = $component / $maximumValue;
        $signed = $this->signPow($value, 0.5);

        return max(0, min(18, (int) floor($signed * 9 + 9.5)));
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private function decodeDc(int $value): array
    {
        return [
            $this->sRgbToLinear(($value >> 16) & 0xFF),
            $this->sRgbToLinear(($value >> 8) & 0xFF),
            $this->sRgbToLinear($value & 0xFF),
        ];
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private function decodeAc(int $value, float $maximumValue): array
    {
        $r = (int) floor($value / (19 * 19));
        $g = (int) floor($value / 19) % 19;
        $b = $value % 19;

        return [
            $this->signPow(($r - 9) / 9, 2.0) * $maximumValue,
            $this->signPow(($g - 9) / 9, 2.0) * $maximumValue,
            $this->signPow(($b - 9) / 9, 2.0) * $maximumValue,
        ];
    }

    private function signPow(float $value, float $exponent): float
    {
        return ($value < 0 ? -1.0 : 1.0) * abs($value) ** $exponent;
    }

    private function sRgbToLinear(int $value): float
    {
        $v = $value / 255;

        return $v <= 0.04045
            ? $v / 12.92
            : (($v + 0.055) / 1.055) ** 2.4;
    }

    private function linearToSRgb(float $value): int
    {
        $v = max(0.0, min(1.0, $value));

        $srgb = $v <= 0.0031308
            ? $v * 12.92
            : 1.055 * $v ** (1 / 2.4) - 0.055;

        // Truncating after the +0.5 bias matches the reference linearTosRGB (round-half-up).
        return max(0, min(255, (int) ($srgb * 255 + 0.5)));
    }

    private function encodeBase83(int $value, int $length): string
    {
        $result = '';

        for ($i = 1; $i <= $length; $i++) {
            $divisor = 83 ** ($length - $i);
            $digit = intdiv($value, $divisor) % 83;
            $result .= self::ALPHABET[$digit];
        }

        return $result;
    }

    private function decodeBase83(string $value): int
    {
        $result = 0;

        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $position = strpos(self::ALPHABET, $value[$i]);
            $result = $result * 83 + ($position === false ? 0 : $position);
        }

        return $result;
    }
}
