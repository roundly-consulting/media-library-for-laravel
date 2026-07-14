<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Observers\MediaObserver;
use RoundlyConsulting\PackageToolkit\Support\ModelResolver;

/**
 * The single seam through which the package resolves the configured `media.media_model`.
 *
 * Every read/write of the media table goes through here, so a host that swaps the model gets it
 * honoured everywhere — including the model events the {@see MediaObserver}
 * hangs its file cleanup on. Narrows the toolkit's `class-string<Model>` to `class-string<Media>`,
 * so no call site needs an inline `@var` override.
 */
final class MediaModel
{
    /**
     * The configured media model.
     *
     * A configured class that is a real Eloquent model but not a {@see Media} cannot serve the
     * package (every action, event and observer is typed against `Media`), so it falls back to the
     * packaged model — the same tolerance the provider's observer registration has always had. A
     * value that is not a model class at all throws.
     *
     * @return class-string<Media>
     */
    public static function class(): string
    {
        $class = ModelResolver::for('media.media_model', Media::class);

        return is_a($class, Media::class, true) ? $class : Media::class;
    }

    public static function new(): Media
    {
        $class = self::class();

        return new $class;
    }

    /** @return Builder<Media> */
    public static function query(): Builder
    {
        return self::class()::query();
    }
}
