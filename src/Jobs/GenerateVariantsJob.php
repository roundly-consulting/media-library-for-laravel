<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RoundlyConsulting\MediaLibrary\Actions\GenerateVariantsAction;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;

/**
 * Queued generation of a media's variants. Carries the media id and the variant names (never the
 * model) so the payload stays small and serializable, then re-resolves the variant definitions
 * from the owning model/bucket when it runs.
 */
final class GenerateVariantsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  list<string>  $variantNames
     */
    public function __construct(
        public readonly int $mediaId,
        public readonly array $variantNames,
    ) {}

    public function handle(GenerateVariantsAction $action): void
    {

        $media = MediaModel::query()->find($this->mediaId);

        if (! $media instanceof Media) {
            return;
        }

        $variants = $media->resolveVariants();

        $requested = array_values(array_filter(
            $variants,
            fn (object $variant): bool => in_array($variant->name, $this->variantNames, true),
        ));

        $action->execute($media, $requested);
    }
}
