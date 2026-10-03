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
     * Absent config resolves the packaged model; anything else must be that model or a subclass
     * of it, or the toolkit's ModelResolver throws InvalidConfigurationException naming the key
     * — a foreign class is never silently replaced.
     *
     * @return class-string<Media>
     */
    public static function class(): string
    {
        return ModelResolver::for('media.media_model', Media::class);
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
