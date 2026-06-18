<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Variants;

/**
 * The `$v->add('thumb')->...` collector passed to a bucket's `registerVariants()` closure
 * (and to the model's `registerMediaVariants()` hook). It accumulates {@see Variant} definitions
 * into a {@see VariantCollection}.
 */
final class VariantRegistrar
{
    public function __construct(
        private readonly VariantCollection $variants = new VariantCollection,
    ) {}

    public function add(string $name): Variant
    {
        $variant = new Variant($name);

        $this->variants->put($variant);

        return $variant;
    }

    public function collection(): VariantCollection
    {
        return $this->variants;
    }
}
