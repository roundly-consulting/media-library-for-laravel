<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Variants;

use RoundlyConsulting\MediaLibrary\Exceptions\MediaIsNotAnImage;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * Builds responsive image output (`srcset` and a full `<img>` tag) from the width-named variants
 * a bucket's responsive ladder generated (§6.6).
 *
 * Responsive widths are synthesized as variants named `responsive-{width}` by the
 * {@see VariantResolver}, run through the existing variant engine. This class only reads the
 * already-generated widths and composes markup; it never generates files itself.
 */
final class ResponsiveImageGenerator
{
    private const PREFIX = 'responsive-';

    /** The variant name a given responsive width is stored under. */
    public static function variantName(int $width): string
    {
        return self::PREFIX.$width;
    }

    /**
     * The ascending list of generated responsive widths for this media.
     *
     * @return list<int>
     */
    public function generatedWidths(Media $media): array
    {
        $widths = [];

        foreach (array_keys($media->generatedVariants()) as $name) {
            if (str_starts_with($name, self::PREFIX)) {
                $width = (int) substr($name, strlen(self::PREFIX));

                if ($width > 0) {
                    $widths[] = $width;
                }
            }
        }

        sort($widths);

        return $widths;
    }

    /**
     * A `srcset` value listing each generated responsive width ascending, e.g.
     * `"…/responsive-320.webp 320w, …/responsive-640.webp 640w"`. Empty when no widths exist.
     *
     * `$base` is unused for the URL (each entry points at its own width variant) but mirrors the
     * accessor signature so callers can stay consistent with the rest of the URL API.
     */
    public function srcset(Media $media, string $base = ''): string
    {
        $entries = [];

        foreach ($this->generatedWidths($media) as $width) {
            $entries[] = $media->getUrl(self::variantName($width)).' '.$width.'w';
        }

        return implode(', ', $entries);
    }

    /**
     * Compose a full `<img>` tag with `src`, `srcset`, optional `sizes`/`alt`, and the LQIP
     * placeholder as an inline blur-up background.
     *
     * @param  array<string, string>  $attributes
     */
    public function imageTag(Media $media, string $base = '', array $attributes = []): string
    {
        if (! $media->isImage()) {
            throw MediaIsNotAnImage::forResponsiveImage($media->uuid);
        }

        $srcset = $this->srcset($media, $base);
        $src = $this->fallbackSrc($media, $base);

        $pairs = ['src' => $src];

        if ($srcset !== '') {
            $pairs['srcset'] = $srcset;
        }

        if (isset($attributes['sizes'])) {
            $pairs['sizes'] = $attributes['sizes'];
        }

        if (isset($attributes['alt'])) {
            $pairs['alt'] = $attributes['alt'];
        }

        if (isset($attributes['class'])) {
            $pairs['class'] = $attributes['class'];
        }

        $style = $this->placeholderStyle($media);

        if ($style !== null) {
            $pairs['style'] = $style;
        }

        return '<img'.$this->renderAttributes($pairs).'>';
    }

    /** The smallest generated width (the lightweight fallback), else the base/original URL. */
    private function fallbackSrc(Media $media, string $base): string
    {
        $widths = $this->generatedWidths($media);

        if ($widths !== []) {
            return $media->getUrl(self::variantName($widths[0]));
        }

        return $media->getUrl($base);
    }

    private function placeholderStyle(Media $media): ?string
    {
        $dataUri = $media->placeholderDataUri();

        if ($dataUri === null) {
            return null;
        }

        return "background-size:cover;background-image:url('{$dataUri}')";
    }

    /**
     * @param  array<string, string>  $pairs
     */
    private function renderAttributes(array $pairs): string
    {
        $rendered = '';

        foreach ($pairs as $name => $value) {
            $rendered .= ' '.$name.'="'.htmlspecialchars($value, ENT_QUOTES).'"';
        }

        return $rendered;
    }
}
