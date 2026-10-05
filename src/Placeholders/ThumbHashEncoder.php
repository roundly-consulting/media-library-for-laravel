<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Placeholders;

use RoundlyConsulting\MediaLibrary\DataTransferObjects\RgbaImage;

/**
 * A pure-PHP, dependency-free port of Evan Wallace's ThumbHash algorithm.
 *
 * Encodes a small {@see RgbaImage} (max 100x100) into a compact binary ThumbHash, returned as a
 * base64 string, and decodes a ThumbHash back into a tiny RGBA raster for a server-side `data:`
 * URI. Byte-for-byte compatible with the reference JavaScript implementation.
 *
 * @see https://github.com/evanw/thumbhash/blob/master/js/thumbhash.js
 */
final class ThumbHashEncoder
{
    /** Encode an image into a base64-encoded ThumbHash. */
    public function encode(RgbaImage $image): string
    {
        $bytes = $this->encodeToBytes($image);

        return base64_encode(pack('C*', ...$bytes));
    }

    /**
     * @return list<int> the raw ThumbHash bytes (0..255)
     */
    public function encodeToBytes(RgbaImage $image): array
    {
        $w = $image->width;
        $h = $image->height;
        $rgba = $image->pixels;

        $avgR = 0.0;
        $avgG = 0.0;
        $avgB = 0.0;
        $avgA = 0.0;

        for ($i = 0, $j = 0; $i < $w * $h; $i++, $j += 4) {
            $alpha = $rgba[$j + 3] / 255;
            $avgR += $alpha / 255 * $rgba[$j];
            $avgG += $alpha / 255 * $rgba[$j + 1];
            $avgB += $alpha / 255 * $rgba[$j + 2];
            $avgA += $alpha;
        }

        if ($avgA > 0) {
            $avgR /= $avgA;
            $avgG /= $avgA;
            $avgB /= $avgA;
        }

        $hasAlpha = $avgA < $w * $h;
        $lLimit = $hasAlpha ? 5 : 7;
        $lx = max(1, (int) round($lLimit * $w / max($w, $h)));
        $ly = max(1, (int) round($lLimit * $h / max($w, $h)));

        $l = [];
        $p = [];
        $q = [];
        $a = [];

        for ($i = 0, $j = 0; $i < $w * $h; $i++, $j += 4) {
            $alpha = $rgba[$j + 3] / 255;
            $r = $avgR * (1 - $alpha) + $alpha / 255 * $rgba[$j];
            $g = $avgG * (1 - $alpha) + $alpha / 255 * $rgba[$j + 1];
            $b = $avgB * (1 - $alpha) + $alpha / 255 * $rgba[$j + 2];
            $l[$i] = ($r + $g + $b) / 3;
            $p[$i] = ($r + $g) / 2 - $b;
            $q[$i] = $r - $g;
            $a[$i] = $alpha;
        }

        [$lDc, $lAc, $lScale] = $this->encodeChannel($l, max(3, $lx), max(3, $ly), $w, $h);
        [$pDc, $pAc, $pScale] = $this->encodeChannel($p, 3, 3, $w, $h);
        [$qDc, $qAc, $qScale] = $this->encodeChannel($q, 3, 3, $w, $h);
        [$aDc, $aAc, $aScale] = $hasAlpha
            ? $this->encodeChannel($a, 5, 5, $w, $h)
            : [0.0, [], 0.0];

        $isLandscape = $w > $h;

        $header24 = $this->round(63 * $lDc)
            | ($this->round(31.5 + 31.5 * $pDc) << 6)
            | ($this->round(31.5 + 31.5 * $qDc) << 12)
            | ($this->round(31 * $lScale) << 18)
            | (($hasAlpha ? 1 : 0) << 23);

        $header16 = ($isLandscape ? $ly : $lx)
            | ($this->round(63 * $pScale) << 3)
            | ($this->round(63 * $qScale) << 9)
            | (($isLandscape ? 1 : 0) << 15);

        $hash = [
            $header24 & 255,
            ($header24 >> 8) & 255,
            $header24 >> 16,
            $header16 & 255,
            $header16 >> 8,
        ];

        if ($hasAlpha) {
            $hash[] = $this->round(15 * $aDc) | ($this->round(15 * $aScale) << 4);
        }

        $acStart = $hasAlpha ? 6 : 5;
        $acIndex = 0;

        $channels = $hasAlpha ? [$lAc, $pAc, $qAc, $aAc] : [$lAc, $pAc, $qAc];

        foreach ($channels as $ac) {
            foreach ($ac as $f) {
                $byteIndex = $acStart + ($acIndex >> 1);
                $hash[$byteIndex] ??= 0;
                $hash[$byteIndex] |= $this->round(15 * $f) << (($acIndex & 1) << 2);
                $acIndex++;
            }
        }

        return array_values(array_map(static fn (int $byte): int => $byte & 255, $hash));
    }

    /**
     * @return array{0: float, 1: int, 2: int} approximate width, height and `hasAlpha` flag
     */
    public function decodeSize(string $hash): array
    {
        $bytes = $this->decodeBytes($hash);

        $header24 = ($bytes[0] ?? 0) | (($bytes[1] ?? 0) << 8) | (($bytes[2] ?? 0) << 16);
        $header16 = ($bytes[3] ?? 0) | (($bytes[4] ?? 0) << 8);
        $hasAlpha = ($header24 >> 23) & 1;
        $isLandscape = ($header16 >> 15) & 1;

        $lx = max(3, $isLandscape ? ($hasAlpha ? 5 : 7) : ($header16 & 7));
        $ly = max(3, $isLandscape ? ($header16 & 7) : ($hasAlpha ? 5 : 7));

        return [(float) $lx, $ly, $hasAlpha];
    }

    public function decodeToRgba(string $hash): RgbaImage
    {
        $bytes = $this->decodeBytes($hash);

        $header24 = ($bytes[0] ?? 0) | (($bytes[1] ?? 0) << 8) | (($bytes[2] ?? 0) << 16);
        $header16 = ($bytes[3] ?? 0) | (($bytes[4] ?? 0) << 8);

        $lDc = ($header24 & 63) / 63;
        $pDc = (($header24 >> 6) & 63) / 31.5 - 1;
        $qDc = (($header24 >> 12) & 63) / 31.5 - 1;
        $lScale = (($header24 >> 18) & 31) / 31;
        $hasAlpha = ($header24 >> 23) & 1;

        $pScale = (($header16 >> 3) & 63) / 63;
        $qScale = (($header16 >> 9) & 63) / 63;
        $isLandscape = ($header16 >> 15) & 1;

        $lx = max(3, $isLandscape ? ($hasAlpha ? 5 : 7) : ($header16 & 7));
        $ly = max(3, $isLandscape ? ($header16 & 7) : ($hasAlpha ? 5 : 7));

        $aDc = 1.0;
        $aScale = 0.0;

        if ($hasAlpha) {
            $aDc = (($bytes[5] ?? 0) & 15) / 15;
            $aScale = ((($bytes[5] ?? 0) >> 4) & 15) / 15;
        }

        // The AC factors are nibbles packed from the first byte after the header (5 bytes, or 6
        // with alpha), counted from zero across all channels.
        $acStart = $hasAlpha ? 6 : 5;
        $acIndex = 0;

        $decodeChannel = function (int $nx, int $ny, float $scale) use ($bytes, $acStart, &$acIndex): array {
            $ac = [];

            for ($cy = 0; $cy < $ny; $cy++) {
                for ($cx = $cy > 0 ? 0 : 1; $cx * $ny < $nx * ($ny - $cy); $cx++) {
                    $byte = $bytes[$acStart + ($acIndex >> 1)] ?? 0;
                    $ac[] = ((($byte >> (($acIndex & 1) << 2)) & 15) / 7.5 - 1) * $scale;
                    $acIndex++;
                }
            }

            return $ac;
        };

        $lAc = $decodeChannel($lx, $ly, $lScale);
        $pAc = $decodeChannel(3, 3, $pScale * 1.25);
        $qAc = $decodeChannel(3, 3, $qScale * 1.25);
        $aAc = $hasAlpha ? $decodeChannel(5, 5, $aScale) : [];

        // The output takes the encoded aspect ratio (thumbHashToApproximateAspectRatio), read
        // from the unclamped factor counts.
        $ratio = $this->approximateAspectRatio($header16, $isLandscape === 1, $hasAlpha === 1);
        $w = max(1, (int) round($ratio > 1 ? 32 : 32 * $ratio));
        $h = max(1, (int) round($ratio > 1 ? 32 / $ratio : 32));

        $pixels = [];

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $l = $this->channelValue($lDc, $lAc, $lx, $ly, $x, $y, $w, $h);
                $p = $this->channelValue($pDc, $pAc, 3, 3, $x, $y, $w, $h);
                $q = $this->channelValue($qDc, $qAc, 3, 3, $x, $y, $w, $h);
                $alpha = $hasAlpha ? $this->channelValue($aDc, $aAc, 5, 5, $x, $y, $w, $h) : 1.0;

                $b = $l - 2 / 3 * $p;
                $r = (3 * $l - $b + $q) / 2;
                $g = $r - $q;

                $pixels[] = $this->clampByte($r);
                $pixels[] = $this->clampByte($g);
                $pixels[] = $this->clampByte($b);
                $pixels[] = $this->clampByte($alpha);
            }
        }

        return new RgbaImage($w, $h, $pixels);
    }

    /** Width over height, as encoded: the unclamped DCT factor counts along each side. */
    private function approximateAspectRatio(int $header16, bool $isLandscape, bool $hasAlpha): float
    {
        $lx = $isLandscape ? ($hasAlpha ? 5 : 7) : $header16 & 7;
        $ly = $isLandscape ? $header16 & 7 : ($hasAlpha ? 5 : 7);

        return $lx > 0 && $ly > 0 ? $lx / $ly : 1.0;
    }

    /**
     * @param  list<float>  $ac
     */
    private function channelValue(float $dc, array $ac, int $nx, int $ny, int $x, int $y, int $w, int $h): float
    {
        $value = $dc;
        $index = 0;

        for ($cy = 0; $cy < $ny; $cy++) {
            $fy = cos(M_PI / $h * ($y + 0.5) * $cy);

            for ($cx = $cy > 0 ? 0 : 1; $cx * $ny < $nx * ($ny - $cy); $cx++) {
                $fx = cos(M_PI / $w * ($x + 0.5) * $cx);
                $value += ($ac[$index] ?? 0.0) * $fx * $fy * 2;
                $index++;
            }
        }

        return $value;
    }

    /**
     * @param  array<int, float>  $channel
     * @return array{0: float, 1: list<float>, 2: float}
     */
    private function encodeChannel(array $channel, int $nx, int $ny, int $w, int $h): array
    {
        $dc = 0.0;
        $ac = [];
        $scale = 0.0;
        $fx = [];

        for ($cy = 0; $cy < $ny; $cy++) {
            for ($cx = 0; $cx * $ny < $nx * ($ny - $cy); $cx++) {
                $f = 0.0;

                for ($x = 0; $x < $w; $x++) {
                    $fx[$x] = cos(M_PI / $w * $cx * ($x + 0.5));
                }

                for ($y = 0; $y < $h; $y++) {
                    $fy = cos(M_PI / $h * $cy * ($y + 0.5));

                    for ($x = 0; $x < $w; $x++) {
                        $f += $channel[$x + $y * $w] * $fx[$x] * $fy;
                    }
                }

                $f /= $w * $h;

                if ($cx > 0 || $cy > 0) {
                    $ac[] = $f;
                    $scale = max($scale, abs($f));
                } else {
                    $dc = $f;
                }
            }
        }

        if ($scale > 0) {
            foreach ($ac as $i => $value) {
                $ac[$i] = 0.5 + 0.5 / $scale * $value;
            }
        }

        return [$dc, $ac, $scale];
    }

    /**
     * @return list<int>
     */
    private function decodeBytes(string $hash): array
    {
        $binary = base64_decode($hash, true);

        if ($binary === false) {
            return [];
        }

        return array_values(unpack('C*', $binary) ?: []);
    }

    /** As the reference: scaled to 0..255, clamped, then truncated (its Uint8Array store). */
    private function clampByte(float $value): int
    {
        return (int) max(0.0, 255 * min(1.0, $value));
    }

    private function round(float $value): int
    {
        return (int) floor($value + 0.5);
    }
}
