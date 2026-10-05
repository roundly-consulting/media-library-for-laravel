<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use RoundlyConsulting\MediaLibrary\Jobs\GenerateVariantsJob;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\ImageDecodeGuard;
use RoundlyConsulting\MediaLibrary\Support\MediaConfig;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;
use RoundlyConsulting\MediaLibrary\Variants\Variant;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Renders a media's variants now or on the queue, per variant: its own `queued()`/`nonQueued()`,
 * else `media.queue_variants_by_default`. Queued ones go out as one {@see GenerateVariantsJob}
 * on `media.queue_connection` and the given queue (else `media.queue_name`).
 *
 * @internal building block — every path that brings media into a bucket (add, bind, attach,
 *           move, copy, replace) generates through here, so they all honour the same queue rules.
 */
final class DispatchVariantsAction
{
    public function __construct(
        private readonly GenerateVariantsAction $generateVariants,
    ) {}

    /**
     * @param  list<Variant>  $variants
     */
    public function execute(Media $media, array $variants, ?string $queue = null): void
    {
        // Only raster images get variants: an SVG is never decoded, so it never gets any.
        if ($variants === [] || ! ImageDecodeGuard::decodes($media->mime_type)) {
            return;
        }

        $sync = [];
        $queued = [];

        foreach ($variants as $variant) {
            if ($this->shouldQueue($variant)) {
                $queued[] = $variant->name;
            } else {
                $sync[] = $variant;
            }
        }

        if ($sync !== []) {
            $this->generateVariants->execute($media, $sync);
        }

        if ($queued !== []) {
            $this->dispatch($media, $queued, $queue);
            $this->syncRecordedVariants($media);
        }
    }

    /**
     * On a `sync` queue the job has already run against its own copy of the row: pick up what it
     * recorded, so a later save of this instance cannot clobber the queued variants' records.
     */
    private function syncRecordedVariants(Media $media): void
    {
        $stored = MediaModel::query()->whereKey($media->getKey())->first([$media->getKeyName(), 'generated_variants']);

        if ($stored instanceof Media) {
            $media->generated_variants = $stored->generated_variants;
            $media->syncOriginalAttribute('generated_variants');
        }
    }

    private function shouldQueue(Variant $variant): bool
    {
        return $variant->isQueued() ?? Config::boolean('media.queue_variants_by_default');
    }

    /**
     * @param  list<string>  $variantNames
     */
    private function dispatch(Media $media, array $variantNames, ?string $queue): void
    {
        $job = new GenerateVariantsJob((int) $media->getKey(), $variantNames);

        $connection = MediaConfig::queueConnection();
        $queue ??= MediaConfig::queueName();

        if ($connection !== null) {
            $job->onConnection($connection);
        }

        if ($queue !== null) {
            $job->onQueue($queue);
        }

        dispatch($job);
    }
}
