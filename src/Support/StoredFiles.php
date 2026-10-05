<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RoundlyConsulting\MediaLibrary\Contracts\PathGenerator;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * The reference count behind shared originals, and the rules for touching stored files safely.
 *
 * Several rows can point at one physical original: a deduplicated add, an `attach()`, a same-disk
 * `copy()`. A file is identified by its `(disk, path)`, and it is shared while ANY other row —
 * soft-deleted ones included, so a restore stays lossless — still points at it. Every write and
 * delete of an original goes through here:
 *
 *  - a shared original is never overwritten (a replacement is written to a free path instead)
 *    and never deleted (only the last referrer removes it);
 *  - a directory is only removed when nothing another row references lives inside it.
 *
 * @internal
 */
final class StoredFiles
{
    public function __construct(
        private readonly PathGenerator $paths,
    ) {}

    /** Whether any row other than `$exceptKey` — soft-deleted ones included — points at the file. */
    public function isReferencedByOthers(string $disk, string $path, int|string|null $exceptKey): bool
    {
        $query = MediaModel::query()
            ->withTrashed()
            ->where('disk', $disk)
            ->where('path', $path);

        if ($exceptKey !== null) {
            $query->whereKeyNot($exceptKey);
        }

        return $query->exists();
    }

    /** Whether another row still points at this media's original. */
    public function originalIsShared(Media $media): bool
    {
        return $this->isReferencedByOthers($media->disk, $media->getPath(), $media->getKey());
    }

    /**
     * A path on `$disk` for this media's original that no other row points at: `$preferred` when
     * it is free, else the layout's own `{dir}{file_name}`, else a unique sub-directory of it.
     */
    public function freePath(Media $media, string $disk, ?string $preferred = null): string
    {
        $canonical = $this->paths->getPath($media).$media->file_name;

        foreach (array_unique(array_filter([$preferred, $canonical])) as $candidate) {
            if (! $this->isReferencedByOthers($disk, $candidate, $media->getKey())) {
                return $candidate;
            }
        }

        return $this->paths->getPath($media).Str::lower(Str::random(16)).'/'.$media->file_name;
    }

    /**
     * Persist the path a row resolves to before another row starts pointing at it, so the
     * reference count (which compares stored paths) can see the sharing.
     */
    public function pin(Media $media): void
    {
        if (is_string($media->path) && $media->path !== '') {
            return;
        }

        $media->path = $media->getPath();

        if ($media->exists) {
            // A plain update: no events, and no `updated_at` bump that would change a CDN cache-bust.
            MediaModel::query()->withTrashed()->whereKey($media->getKey())->toBase()->update(['path' => $media->path]);
            $media->syncOriginalAttribute('path');
        }
    }

    /**
     * Delete a file the row no longer needs once the surrounding transaction commits — at once
     * when none is open. Inside a host's transaction the package's own is only a savepoint, so
     * deleting earlier would let a host rollback restore a row pointing at a removed file.
     *
     * It is skipped when, by then, some row stores the file as its original, or `$media` records
     * a variant at it (a re-render landed on the same path).
     */
    public function deleteAfterCommit(Media $media, string $disk, string $path): void
    {
        DB::afterCommit(function () use ($media, $disk, $path): void {
            if ($this->isReferencedByOthers($disk, $path, null) || $this->recordsVariantAt($media, $disk, $path)) {
                return;
            }

            Storage::disk($disk)->delete($path);
        });
    }

    /** Delete this media's generated variant files, each from the disk it was written to. */
    public function deleteVariants(Media $media): void
    {
        foreach (array_keys($media->generatedVariants()) as $name) {
            Storage::disk($media->diskFor($name))->delete($media->getPath($name));
        }
    }

    /** Delete this media's original unless another row still points at it. */
    public function deleteOriginalUnlessShared(Media $media): void
    {
        if (! $this->originalIsShared($media)) {
            Storage::disk($media->disk)->delete($media->getPath());
        }
    }

    /**
     * After a media's files are gone, remove the directories they lived in — but never one that
     * still holds a file another row points at (a shared original), and never a directory the
     * layout does not dedicate to this media (one without its uuid as a path segment) unless it
     * is already empty.
     */
    public function tidyDirectories(Media $media): void
    {
        $disks = array_values(array_unique(array_merge(
            [$media->disk, $media->variants_disk ?? $media->disk],
            array_map(static fn ($variant): string => $variant->disk, array_values($media->generatedVariants())),
        )));

        $candidates = [];

        foreach ($disks as $disk) {
            $candidates[] = [$disk, rtrim($media->getPathForVariantsDirectory(), '/')];

            if (str_starts_with($media->getPath(), $media->uuid.'/')) {
                $candidates[] = [$disk, $media->uuid];
            }
        }

        $candidates[] = [$media->disk, dirname($media->getPath())];

        foreach ($candidates as [$disk, $directory]) {
            $this->tidy($media, $disk, $directory);
        }
    }

    /** Whether `$directory` belongs to this media alone by the layout — its uuid is a path segment. */
    public function isOwnDirectory(Media $media, string $directory): bool
    {
        return in_array($media->uuid, explode('/', trim($directory, '/')), true);
    }

    /**
     * Of `$paths` on `$disk`, the ones some row stores as its original.
     *
     * @param  list<string>  $paths
     * @return list<string>
     */
    public function referencedOriginals(string $disk, array $paths): array
    {
        $referenced = [];

        foreach (array_chunk($paths, 500) as $chunk) {
            foreach (MediaModel::query()->withTrashed()->where('disk', $disk)->whereIn('path', $chunk)->pluck('path') as $path) {
                $referenced[] = (string) $path;
            }
        }

        return $referenced;
    }

    private function recordsVariantAt(Media $media, string $disk, string $path): bool
    {
        foreach (array_keys($media->generatedVariants()) as $name) {
            if ($media->diskFor($name) === $disk && $media->getPath($name) === $path) {
                return true;
            }
        }

        return false;
    }

    private function tidy(Media $media, string $disk, string $directory): void
    {
        if ($directory === '' || $directory === '.') {
            return;
        }

        $storage = Storage::disk($disk);
        $remaining = array_values($storage->allFiles($directory));

        if ($remaining !== [] && (! $this->isOwnDirectory($media, $directory) || $this->referencedOriginals($disk, $remaining) !== [])) {
            return;
        }

        $storage->deleteDirectory($directory);
    }
}
