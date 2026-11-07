<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\StorageUsage;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * Measures the bytes the media a query selects store, counting a shared original once.
 *
 * A file is identified by its `(disk, path)`, the same identity the shared-original reference
 * count uses: rows that share one collapse to a single `(disk, path, size)`, counted once. A row
 * without a stored `path` (written before paths were) has never been shared — a row starts
 * sharing only after its path is pinned — so it counts on its own. Originals are two aggregate
 * queries; variant sizes live in the `generated_variants` json, so they are summed from the
 * selected rows in chunks, reading only the key and that column.
 *
 * The query is used as given — scope it to a tenant, an owner or a bucket, include soft-deleted
 * rows (their files are still stored) with `withTrashed()`. Its ordering is ignored.
 */
final class MeasureStorageUsageAction
{
    private const int CHUNK = 1000;

    /**
     * @param  Builder<Media>  $query
     */
    public function execute(Builder $query): StorageUsage
    {
        $scoped = (clone $query)->reorder();
        $model = $scoped->getModel();
        $size = $model->qualifyColumn('size');
        $path = $model->qualifyColumn('path');
        $disk = $model->qualifyColumn('disk');

        $unpinned = (int) (clone $scoped)->whereNull($path)->sum($size);

        // One row per stored file. Rows sharing a file share its bytes — a shared original is never
        // overwritten — so they agree on its size.
        $files = (clone $scoped)->whereNotNull($path)->toBase()->select([$disk, $path, $size])->distinct();

        $pinned = (int) $files->newQuery()->fromSub($files, 'stored_originals')->sum('size');

        return new StorageUsage(
            originalBytes: $unpinned + $pinned,
            variantBytes: $this->variantBytes($scoped),
            referencedOriginalBytes: (int) (clone $scoped)->sum($size),
        );
    }

    /**
     * @param  Builder<Media>  $query
     */
    private function variantBytes(Builder $query): int
    {
        $model = $query->getModel();
        $variants = $model->qualifyColumn('generated_variants');
        $total = 0;

        (clone $query)
            ->whereNotNull($variants)
            ->select([$model->getQualifiedKeyName(), $variants])
            ->lazyById(self::CHUNK, $model->getQualifiedKeyName(), $model->getKeyName())
            ->each(static function (Media $media) use (&$total): void {
                $total += $media->variantsSize();
            });

        return $total;
    }
}
