<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use RoundlyConsulting\MediaLibrary\Buckets\BucketGuard;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\MediaLibrary\Events\MediaHasBeenAdded;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;
use RoundlyConsulting\MediaLibrary\Support\StoredFiles;

/**
 * Attaches an existing (often global) media to a model by reference (§6.9): a NEW row is created
 * for the target owner/bucket that points at the same stored original on the same
 * `(disk, visibility)` — zero bytes copied, exactly the dedup "reference the canonical file" path.
 *
 * The shared original is refcount-guarded on later delete/move/replace because the new row points
 * at the same stored file. Variants are per-row, so the TARGET bucket's variants are generated
 * fresh for the new row rather than shared. The target bucket's acceptance and single-file rules
 * apply.
 */
final class AttachMediaAction
{
    public function __construct(
        private readonly DispatchVariantsAction $dispatchVariants,
        private readonly BucketGuard $guard,
        private readonly StoredFiles $files,
    ) {}

    /**
     * @throws FileUnacceptableForBucket when the target bucket does not accept the media
     */
    public function execute(Media $source, HasMedia|Model|null $toModel = null, string $bucket = 'default'): Media
    {
        $this->guard->ensureSavedOwner($toModel);

        $targetBucket = $this->guard->bucketFor($toModel, $bucket);
        $this->guard->ensureAcceptsMedia($targetBucket, $bucket, $source);

        $media = $this->referenceRow($source, $toModel, $bucket);

        $media->save();

        if ($toModel instanceof Model) {
            $this->guard->enforceSingleFile($targetBucket, $toModel, $bucket, $media);
        }

        event(new MediaHasBeenAdded($media));

        $this->dispatchVariants->execute($media, $media->isImage() ? $media->resolveVariants() : []);

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

        // disk/visibility/checksum/mime/size/width/height/placeholders carry over verbatim, and the
        // path is the source's resolved one (pinned on the source too, so the refcount sees it).
        $this->files->pin($source);
        $media->path = $source->getPath();

        if ($toModel instanceof Model) {
            $media->model_type = $toModel->getMorphClass();
            $media->model_id = $toModel->getKey();
        } else {
            $media->model_type = null;
            $media->model_id = null;
        }

        $media->setRelation('model', $toModel instanceof Model ? $toModel : null);
        $media->order_column = $this->nextOrderColumn($media);

        return $media;
    }

    private function nextOrderColumn(Media $media): int
    {
        $query = MediaModel::query()->where('bucket_name', $media->bucket_name);

        if ($media->model_type !== null) {
            $query->where('model_type', $media->model_type)->where('model_id', $media->model_id);
        } else {
            $query->whereNull('model_type')->whereNull('model_id');
        }

        return (int) $query->max('order_column') + 1;
    }
}
