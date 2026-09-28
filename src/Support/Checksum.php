<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Enums\ChecksumAlgorithm;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;
use RoundlyConsulting\PackageToolkit\Support\Config;

/**
 * Content hashing plus the `(disk, visibility, checksum)` dedup lookup (§7.1/§7.2).
 *
 * The hash is both the dedup key (identical bytes on the same disk+visibility share one physical
 * original) and the integrity baseline (re-hash on read/verify to detect drift). Whether a stored
 * file is still shared is a question about the file, not the hash — see {@see StoredFiles}.
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

    /**
     * The configured algorithm (`sha256` when unset).
     *
     * @throws InvalidConfigurationException for anything but a {@see ChecksumAlgorithm}
     */
    public function algorithm(): string
    {
        $configured = config('media.checksum_algorithm');

        if ($configured === null || $configured === '') {
            return ChecksumAlgorithm::Sha256->value;
        }

        return Config::enum('media.checksum_algorithm', ChecksumAlgorithm::class)->value;
    }
}
