<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Handles;

use RoundlyConsulting\MediaLibrary\MediaLibraryManager;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Variants\Variant;

/**
 * Variant operations for one media — `MediaLibrary::variants($media)`.
 *
 * The definitions come from the owning model's bucket (inline, responsive widths and the model's
 * `registerMediaVariants()` hook), so global media has none. `regenerate()` delegates to the
 * manager's flat verb, so a fake records it.
 */
final readonly class MediaVariants
{
    public function __construct(
        private MediaLibraryManager $manager,
        private Media $media,
    ) {}

    /**
     * Every variant definition that applies to this media.
     *
     * @return list<Variant>
     */
    public function all(): array
    {
        return $this->media->resolveVariants();
    }

    /**
     * The names of the variants whose files have been generated.
     *
     * @return list<string>
     */
    public function generated(): array
    {
        return array_map('strval', array_keys($this->media->generatedVariants()));
    }

    /**
     * The names of applicable variants that have not been generated yet.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $missing = [];

        foreach ($this->all() as $variant) {
            if (! $this->media->hasGeneratedVariant($variant->name)) {
                $missing[] = $variant->name;
            }
        }

        return $missing;
    }

    /**
     * Re-render this media's variants — only missing ones unless `$force`; `$only` narrows the run.
     * Returns the rendered names.
     *
     * @param  list<string>  $only
     * @return list<string>
     */
    public function regenerate(array $only = [], bool $force = false): array
    {
        return $this->manager->regenerate($this->media, $only, $force);
    }
}
