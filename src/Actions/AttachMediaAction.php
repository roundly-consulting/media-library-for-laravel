<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\MediaLibrary\Events\MediaHasBeenAdded;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * Attaches an existing (often global) media to a model by reference (§6.9): a NEW row is created
 * for the target owner/bucket that points at the same stored original on the same
 * `(disk, visibility)` — zero bytes copied, exactly the dedup "reference the canonical file" path.
 *
 * The shared original is refcount-guarded on later delete/move because the new row shares the
 * source's `checksum`. Variants are per-row, so the TARGET bucket's variants are generated fresh
 * for the new row rather than shared.
 */
final class AttachMediaAction
{
    public function __construct(
        private readonly GenerateVariantsAction $generateVariants,
    ) {}

    public function execute(Media $source, HasMedia|Model|null $toModel = null, string $bucket = 'default'): Media
    {
        $media = $this->referenceRow($source, $toModel, $bucket);

        $media->save();

        event(new MediaHasBeenAdded($media));

        $this->generateVariants($media);

        return $media;
    }

    private function referenceRow(Media $source, HasMedia|Model|null $toModel, string $bucket): Media
    {
        // Replicate the source's stored-file pointers so the new row resolves to the exact same
        // bytes — but never inherit per-row state (variants, draft tokens) or its identity.
        $media = $source->replicate([
            'uuid',
            'model_type',
            'model_id',
            'bucket_name',
            'generated_variants',
            'order_column',
            'draft_token',
            'draft_expires_at',
        ]);

        $media->uuid = (string) Str::uuid();
        $media->bucket_name = $bucket;
        $media->generated_variants = [];
        $media->draft_token = null;
        $media->draft_expires_at = null;

        // path/disk/visibility/checksum/mime/size/width/height/placeholders carry over verbatim,
        // so the new row references the canonical original with zero bytes copied.

        if ($toModel instanceof Model) {
            $media->model_type = $toModel->getMorphClass();
            $media->model_id = $toModel->getKey();
        } else {
            $media->model_type = null;
            $media->model_id = null;
        }

        $media->order_column = $this->nextOrderColumn($media);

        return $media;
    }

    private function generateVariants(Media $media): void
    {
        if (! $media->isImage()) {
            return;
        }

        $variants = $media->resolveVariants();

        if ($variants !== []) {
            $this->generateVariants->execute($media, $variants);
        }
    }

    private function nextOrderColumn(Media $media): int
    {
        $query = Media::query()->where('bucket_name', $media->bucket_name);

        if ($media->model_type !== null) {
            $query->where('model_type', $media->model_type)->where('model_id', $media->model_id);
        } else {
            $query->whereNull('model_type')->whereNull('model_id');
        }

        return (int) $query->max('order_column') + 1;
    }
}
