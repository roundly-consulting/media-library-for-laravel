<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Buckets;

use Closure;
use RoundlyConsulting\MediaLibrary\Variants\VariantCollection;
use RoundlyConsulting\MediaLibrary\Variants\VariantRegistrar;

/**
 * Definition of a named media bucket on a model (or global).
 *
 * Buckets carry storage/visibility defaults and acceptance rules that adders consult when
 * a file is attached. They are declared in a host model's `registerMediaBuckets()` hook.
 */
final class MediaBucket
{
    private ?string $disk = null;

    private ?string $variantsDisk = null;

    /** @var Closure(VariantRegistrar): void|null */
    private ?Closure $variantsCallback = null;

    private ?VariantCollection $variants = null;

    /** @var list<string> */
    private array $acceptedMimeTypes = [];

    private bool $singleFile = false;

    private ?string $visibility = null;

    private ?string $fallbackUrl = null;

    private ?string $fallbackPath = null;

    public function __construct(
        public readonly string $name,
    ) {}

    public function useDisk(string $disk): self
    {
        $this->disk = $disk;

        return $this;
    }

    public function getDisk(): ?string
    {
        return $this->disk;
    }

    public function storingVariantsOnDisk(string $disk): self
    {
        $this->variantsDisk = $disk;

        return $this;
    }

    public function getVariantsDisk(): ?string
    {
        return $this->variantsDisk;
    }

    /**
     * @param  list<string>  $mimeTypes
     */
    public function acceptsMimeTypes(array $mimeTypes): self
    {
        $this->acceptedMimeTypes = $mimeTypes;

        return $this;
    }

    /** @return list<string> */
    public function getAcceptedMimeTypes(): array
    {
        return $this->acceptedMimeTypes;
    }

    public function accepts(?string $mimeType): bool
    {
        if ($this->acceptedMimeTypes === []) {
            return true;
        }

        if ($mimeType === null) {
            return false;
        }

        return in_array($mimeType, $this->acceptedMimeTypes, true);
    }

    public function singleFile(bool $singleFile = true): self
    {
        $this->singleFile = $singleFile;

        return $this;
    }

    public function isSingleFile(): bool
    {
        return $this->singleFile;
    }

    public function private(): self
    {
        $this->visibility = 'private';

        return $this;
    }

    public function public(): self
    {
        $this->visibility = 'public';

        return $this;
    }

    public function withVisibility(string $visibility): self
    {
        $this->visibility = $visibility;

        return $this;
    }

    public function getVisibility(): ?string
    {
        return $this->visibility;
    }

    public function useFallbackUrl(string $url): self
    {
        $this->fallbackUrl = $url;

        return $this;
    }

    public function getFallbackUrl(): ?string
    {
        return $this->fallbackUrl;
    }

    public function useFallbackPath(string $path): self
    {
        $this->fallbackPath = $path;

        return $this;
    }

    public function getFallbackPath(): ?string
    {
        return $this->fallbackPath;
    }

    /**
     * Declare the bucket's image variants. The closure receives a {@see VariantRegistrar}.
     *
     * @param  Closure(VariantRegistrar): void  $callback
     */
    public function registerVariants(Closure $callback): self
    {
        $this->variantsCallback = $callback;
        $this->variants = null;

        return $this;
    }

    public function hasVariants(): bool
    {
        return $this->variantsCallback !== null;
    }

    /** Resolve (memoize) and return this bucket's variant definitions. */
    public function variants(): VariantCollection
    {
        if ($this->variants instanceof VariantCollection) {
            return $this->variants;
        }

        $registrar = new VariantRegistrar;

        if ($this->variantsCallback !== null) {
            ($this->variantsCallback)($registrar);
        }

        return $this->variants = $registrar->collection();
    }
}
