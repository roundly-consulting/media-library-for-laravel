<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * Content hashing plus the `(disk, visibility, checksum)` dedup/refcount helpers (§7.1/§7.2).
 *
 * The hash is both the dedup key (identical bytes on the same disk+visibility share one physical
 * original) and the integrity baseline (re-hash on read/verify to detect drift). There is no
 * second table — the `media` row's `checksum` column and its composite index are the whole
 * mechanism.
 */
final class Checksum
{
    /** Hash a local file with the configured algorithm, or null when it can't be read. */
    public function forLocalFile(string $path): ?string
    {
        $algorithm = $this->algorithm();

        $hash = @hash_file($algorithm, $path);

        return $hash === false ? null : $hash;
    }

    /** Re-hash a media's stored original (streamed, so large files aren't buffered fully). */
    public function forStoredOriginal(Media $media): ?string
    {
        $stream = Storage::disk($media->disk)->readStream($media->getPath());

        if (! is_resource($stream)) {
            return null;
        }

        $context = hash_init($this->algorithm());
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }

    /**
     * The existing, non-trashed canonical row whose original this add should reuse, or null when
     * nothing matches (so a fresh copy is stored). Excludes the row itself when given.
     */
    public function canonicalFor(string $disk, string $visibility, string $checksum, ?int $exceptId = null): ?Media
    {
        return $this->matching($disk, $visibility, $checksum, $exceptId)
            ->orderBy('id')
            ->first();
    }

    /**
     * Whether any OTHER non-trashed row still references the same physical original — i.e. this
     * media is NOT the last referrer, so the shared file must be left in place. The media's own
     * row is always excluded.
     */
    public function isSharedByOthers(Media $media): bool
    {
        if (! is_string($media->checksum) || $media->checksum === '') {
            return false;
        }

        return $this->matching($media->disk, $media->visibility, $media->checksum, (int) $media->getKey())->exists();
    }

    /**
     * @return Builder<Media>
     */
    private function matching(string $disk, string $visibility, string $checksum, ?int $exceptId): Builder
    {

        $query = MediaModel::query()
            ->where('disk', $disk)
            ->where('visibility', $visibility)
            ->where('checksum', $checksum);

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        return $query;
    }

    public function algorithm(): string
    {
        $algorithm = config('media.checksum_algorithm');

        return is_string($algorithm) && $algorithm !== '' ? $algorithm : 'sha256';
    }
}
