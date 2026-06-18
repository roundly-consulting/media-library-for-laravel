<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Variants;

use RoundlyConsulting\MediaLibrary\Contracts\ImageDriver;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\ManipulationSet;
use RoundlyConsulting\MediaLibrary\Exceptions\InvalidVariant;

/**
 * A fluent, declarative definition of one image derivative (a "variant").
 *
 * Variants are declared per bucket via {@see VariantRegistrar} and resolved into an immutable
 * {@see ManipulationSet} the variant engine applies through the active {@see ImageDriver}.
 */
final class Variant
{
    private const FORMATS = ['jpg', 'jpeg', 'png', 'webp', 'avif', 'gif'];

    private ?int $width = null;

    private ?int $height = null;

    /** @var 'contain'|'cover'|'crop'|'fill'|'stretch' */
    private string $fit = 'contain';

    private ?string $format = null;

    private ?int $quality = null;

    private ?string $background = null;

    private int $sharpen = 0;

    /** @var list<string> */
    private array $buckets = [];

    private ?bool $queued = null;

    private ?string $disk = null;

    public function __construct(
        public readonly string $name,
    ) {}

    public function width(int $width): self
    {
        $this->width = $width;

        return $this;
    }

    public function height(int $height): self
    {
        $this->height = $height;

        return $this;
    }

    public function fit(string $mode): self
    {
        $this->fit = match ($mode) {
            'contain' => 'contain',
            'cover' => 'cover',
            'crop' => 'crop',
            'fill' => 'fill',
            'stretch' => 'stretch',
            default => throw InvalidVariant::invalidFitMode($mode),
        };

        return $this;
    }

    public function format(string $format): self
    {
        $format = strtolower($format);

        if (! in_array($format, self::FORMATS, true)) {
            throw InvalidVariant::unsupportedFormat($format, 'media');
        }

        $this->format = $format === 'jpeg' ? 'jpg' : $format;

        return $this;
    }

    /** The explicitly-requested output format, or null to inherit the original's. */
    public function getFormat(): ?string
    {
        return $this->format;
    }

    public function quality(int $quality): self
    {
        if ($quality < 1 || $quality > 100) {
            throw InvalidVariant::invalidQuality($quality);
        }

        $this->quality = $quality;

        return $this;
    }

    public function background(string $color): self
    {
        $this->background = $color;

        return $this;
    }

    public function sharpen(int $amount): self
    {
        $this->sharpen = $amount;

        return $this;
    }

    public function performOnBuckets(string ...$names): self
    {
        $this->buckets = array_values($names);

        return $this;
    }

    /** @return list<string> */
    public function buckets(): array
    {
        return $this->buckets;
    }

    public function appliesToBucket(string $bucket): bool
    {
        return $this->buckets === [] || in_array($bucket, $this->buckets, true);
    }

    public function queued(): self
    {
        $this->queued = true;

        return $this;
    }

    public function nonQueued(): self
    {
        $this->queued = false;

        return $this;
    }

    public function isQueued(): ?bool
    {
        return $this->queued;
    }

    public function storeOnDisk(string $disk): self
    {
        $this->disk = $disk;

        return $this;
    }

    public function disk(): ?string
    {
        return $this->disk;
    }

    /**
     * Validate the requested format against the active driver, then resolve a concrete,
     * immutable manipulation set (filling in config-backed defaults).
     */
    public function resolve(ImageDriver $driver, string $sourceExtension): ManipulationSet
    {
        $format = $this->format ?? $this->normalizeExtension($sourceExtension);

        if (! $driver->supportsFormat($format)) {
            throw InvalidVariant::unsupportedFormat($format, $driver->name());
        }

        return new ManipulationSet(
            name: $this->name,
            width: $this->width,
            height: $this->height,
            fit: $this->fit,
            format: $format,
            quality: $this->quality ?? $this->configQuality(),
            background: $this->background ?? $this->configBackground(),
            sharpen: $this->sharpen,
        );
    }

    private function normalizeExtension(string $extension): string
    {
        $extension = strtolower($extension);
        $extension = $extension === 'jpeg' ? 'jpg' : $extension;

        return in_array($extension, self::FORMATS, true) ? $extension : 'jpg';
    }

    private function configQuality(): int
    {
        $quality = config('media.variant.quality');

        return is_int($quality) ? $quality : 75;
    }

    private function configBackground(): string
    {
        $background = config('media.variant.background');

        return is_string($background) ? $background : '#ffffff';
    }
}
