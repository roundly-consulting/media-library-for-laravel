<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Deduplication;

use Illuminate\Database\Eloquent\Builder;
use RoundlyConsulting\MediaLibrary\Contracts\DeduplicationScope;
use RoundlyConsulting\MediaLibrary\Contracts\PathGenerator;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * `media.deduplicate_scope` = `path_prefix`: an original is shared only with a file stored under
 * the same directory prefix the path generator gives this media — the part of its directory
 * ahead of the media's own uuid segment (`tenants/42/` for `tenants/42/{uuid}/`). When the
 * directory has no uuid segment, the whole directory is the prefix.
 *
 * With the default layout (`{uuid}/`) the prefix is empty, so this shares like `global` — it is
 * meant for a path generator that puts a tenant (or any other boundary) first. Deleting such a
 * prefix then never removes a file a row outside it points at through dedup. Rows without a
 * stored `path` (written before paths were) are never shared under this scope.
 */
final class PathPrefixDeduplicationScope implements DeduplicationScope
{
    public function __construct(
        private readonly PathGenerator $paths,
    ) {}

    public function constrain(Builder $candidates, Media $media): Builder
    {
        $prefix = $this->prefixFor($media);
        $candidates->whereNotNull('path');

        if ($prefix === '') {
            return $candidates;
        }

        // substr() reads the same on sqlite, pgsql and mysql, with no LIKE wildcard to escape. The
        // candidates are a query on the media table alone, so the bare column name is unambiguous.
        return $candidates->whereRaw('substr(path, 1, ?) = ?', [mb_strlen($prefix), $prefix]);
    }

    /** The directory prefix `$media`'s original is stored under, ending in a slash (or empty). */
    public function prefixFor(Media $media): string
    {
        $segments = explode('/', trim($this->paths->getPath($media), '/'));
        $own = array_search($media->uuid, $segments, true);

        if ($own !== false) {
            $segments = array_slice($segments, 0, $own);
        }

        $segments = array_values(array_filter($segments, static fn (string $segment): bool => $segment !== ''));

        return $segments === [] ? '' : implode('/', $segments).'/';
    }
}
