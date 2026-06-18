<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Variants;

use IteratorAggregate;
use Traversable;

/**
 * An ordered, name-keyed set of {@see Variant} definitions.
 *
 * @implements IteratorAggregate<string, Variant>
 */
final class VariantCollection implements IteratorAggregate
{
    /** @var array<string, Variant> */
    private array $variants = [];

    public function put(Variant $variant): void
    {
        $this->variants[$variant->name] = $variant;
    }

    public function get(string $name): ?Variant
    {
        return $this->variants[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->variants);
    }

    public function isEmpty(): bool
    {
        return $this->variants === [];
    }

    /** @return list<Variant> */
    public function all(): array
    {
        return array_values($this->variants);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->variants);
    }

    /** @return list<Variant> */
    public function forBucket(string $bucket): array
    {
        return array_values(array_filter(
            $this->variants,
            static fn (Variant $variant): bool => $variant->appliesToBucket($bucket),
        ));
    }

    public function getIterator(): Traversable
    {
        yield from $this->variants;
    }
}
