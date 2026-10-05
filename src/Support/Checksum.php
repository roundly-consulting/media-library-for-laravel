<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Enums\ChecksumAlgorithm;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

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
     * Whether a media's stored original still hashes to its recorded checksum — under the
     * algorithm that recorded it, not merely today's `media.checksum_algorithm`.
     *
     * The row does not store its algorithm, so every accepted algorithm whose digest has the
     * recorded length is a candidate (the configured one included): changing the setting must
     * not turn every file stored before into "drifted". The file is read once, hashed by every
     * candidate at the same time.
     */
    public function matchesStoredOriginal(Media $media): bool
    {
        $recorded = strtolower((string) $media->checksum);

        if ($recorded === '') {
            return false;
        }

        $stream = Storage::disk($media->disk)->readStream($media->getPath());

        if (! is_resource($stream)) {
            return false;
        }

        $contexts = [];

        foreach ($this->candidatesFor($recorded) as $algorithm) {
            $contexts[] = hash_init($algorithm);
        }

        while (! feof($stream)) {
            $chunk = fread($stream, 1048576);

            if ($chunk === false || $chunk === '') {
                break;
            }

            foreach ($contexts as $context) {
                hash_update($context, $chunk);
            }
        }

        fclose($stream);

        foreach ($contexts as $context) {
            if (hash_equals($recorded, hash_final($context))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The configured algorithm, then every other accepted one with a digest of this length.
     *
     * @return list<string>
     */
    private function candidatesFor(string $checksum): array
    {
        $configured = MediaConfig::checksumAlgorithm();
        $candidates = [$configured->value];

        foreach (ChecksumAlgorithm::cases() as $algorithm) {
            if ($algorithm !== $configured && $algorithm->digestLength() === strlen($checksum)) {
                $candidates[] = $algorithm->value;
            }
        }

        return $candidates;
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
        return MediaConfig::checksumAlgorithm()->value;
    }
}
