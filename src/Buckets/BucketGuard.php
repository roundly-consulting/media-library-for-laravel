<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Buckets;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\MediaLibrary\Actions\DeleteMediaAction;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\MediaLibrary\Exceptions\MediaOwnerNotSaved;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;

/**
 * Applies a bucket's acceptance rules to whatever enters it — an add, a bound draft, an attached,
 * moved or copied media, a replacement — so the rules `rulesFor()` derives are also the rules the
 * package itself enforces: the mime allowlist, the size cap (the bucket's own, else
 * `media.max_file_size`) and, for images, the declared min/max dimensions.
 *
 * It also owns the single-file rule: once a media has entered a `singleFile()` bucket, the owner's
 * other media in that bucket are permanently deleted (each firing `MediaHasBeenDeleted`).
 *
 * @internal
 */
final class BucketGuard
{
    public function __construct(
        private readonly DeleteMediaAction $deleteMedia,
    ) {}

    /**
     * Media only ever belongs to a saved, keyed model.
     *
     * @throws MediaOwnerNotSaved
     */
    public function ensureSavedOwner(HasMedia|Model|null $owner): void
    {
        if ($owner instanceof Model && (! $owner->exists || $owner->getKey() === null)) {
            throw MediaOwnerNotSaved::forModel($owner);
        }
    }

    /** The bucket `$owner` declares under `$name`; null for global media or an undeclared bucket. */
    public function bucketFor(HasMedia|Model|null $owner, string $name): ?MediaBucket
    {
        return $owner instanceof HasMedia ? $owner->resolveMediaBucket($name) : null;
    }

    /**
     * @throws FileUnacceptableForBucket
     */
    public function ensureAccepts(
        ?MediaBucket $bucket,
        string $bucketName,
        ?string $mimeType,
        int $size,
        ?int $width,
        ?int $height,
    ): void {
        if ($bucket !== null && ! $bucket->accepts($mimeType)) {
            throw FileUnacceptableForBucket::mimeType($mimeType ?? 'unknown', $bucketName);
        }

        $maxFileSize = BucketValidationRules::maxFileSizeFor($bucket);

        if ($maxFileSize !== null && $size > $maxFileSize) {
            throw FileUnacceptableForBucket::tooLarge($size, $maxFileSize, $bucketName);
        }

        if ($bucket !== null && str_starts_with((string) $mimeType, 'image/')
            && ! self::fitsDimensions($bucket->getMinDimensions(), $bucket->getMaxDimensions(), $width, $height)) {
            throw FileUnacceptableForBucket::dimensions($width, $height, $bucketName);
        }
    }

    /**
     * @throws FileUnacceptableForBucket
     */
    public function ensureAcceptsMedia(?MediaBucket $bucket, string $bucketName, Media $media): void
    {
        $this->ensureAccepts($bucket, $bucketName, $media->mime_type, $media->size, $media->width, $media->height);
    }

    /**
     * Enforce `singleFile()`: permanently delete every other media `$owner` holds in the bucket.
     *
     * Two adds for one owner can race (a double submit). Enforcement is serialized on the owner's
     * row lock, and an entrant that a concurrent one has already removed backs off — the bucket
     * is the other's now — so exactly one media is left, never none.
     */
    public function enforceSingleFile(?MediaBucket $bucket, Model $owner, string $bucketName, Media $keep): void
    {
        if ($bucket === null || ! $bucket->isSingleFile()) {
            return;
        }

        $owner->getConnection()->transaction(function () use ($owner, $bucketName, $keep): void {
            $owner->newQueryWithoutScopes()
                ->whereKey($owner->getKey())
                ->lockForUpdate()
                ->first([$owner->getKeyName()]);

            if (! MediaModel::query()->whereKey($keep->getKey())->exists()) {
                return;
            }

            MediaModel::query()
                ->forModel($owner)
                ->inBucket($bucketName)
                ->whereKeyNot($keep->getKey())
                ->get()
                ->each(fn (Media $media) => $this->deleteMedia->execute($media));
        });
    }

    /**
     * Whether an image of `$width` x `$height` (as displayed) fits the bounds; an unreadable size
     * never fits a bounded box. Shared with the derived `media_dimensions` validation rule.
     *
     * @param  array{0: int, 1: int}|null  $min
     * @param  array{0: int, 1: int}|null  $max
     */
    public static function fitsDimensions(?array $min, ?array $max, ?int $width, ?int $height): bool
    {
        if ($min === null && $max === null) {
            return true;
        }

        if ($width === null || $height === null) {
            return false;
        }

        if ($min !== null && ($width < $min[0] || $height < $min[1])) {
            return false;
        }

        return $max === null || ($width <= $max[0] && $height <= $max[1]);
    }
}
