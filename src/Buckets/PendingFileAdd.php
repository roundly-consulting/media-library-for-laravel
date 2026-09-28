<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Buckets;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\MediaLibrary\Actions\AddMediaAction;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\AddedFile;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * Fluent builder collecting per-add overrides (name, disks, visibility, custom properties)
 * before a terminal `toMediaBucket()` / `toBucket()` call persists the {@see Media} row.
 *
 * The same builder backs both model-bound media (non-null owner) and global media (null owner).
 * The terminal hands its snapshot to the manager that built it, which runs {@see AddMediaAction}
 * — so a faked manager records the add instead of storing it.
 */
final class PendingFileAdd
{
    private ?string $name = null;

    private ?string $fileName = null;

    private ?string $diskOverride = null;

    private ?string $variantsDiskOverride = null;

    private ?string $visibility = null;

    private bool $preserveOriginal = false;

    private ?string $queue = null;

    private bool $draft = false;

    /** @var array<string, mixed> */
    private array $customProperties = [];

    /** @internal built by {@see MediaLibraryManager} — start an add with `MediaLibrary::add()` or `MediaLibrary::for($model)->add()` */
    public function __construct(
        private readonly MediaLibraryManager $manager,
        private readonly HasMedia|Model|null $owner,
        private readonly AddedFile $file,
    ) {}

    public function usingName(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function usingFileName(string $fileName): self
    {
        $this->fileName = $fileName;

        return $this;
    }

    public function useDisk(string $disk): self
    {
        $this->diskOverride = $disk;

        return $this;
    }

    public function storingVariantsOnDisk(string $disk): self
    {
        $this->variantsDiskOverride = $disk;

        return $this;
    }

    public function withVisibility(string $visibility): self
    {
        $this->visibility = $visibility;

        return $this;
    }

    public function preservingOriginal(bool $preserve = true): self
    {
        $this->preserveOriginal = $preserve;

        return $this;
    }

    /** Queue name used when this add's variants are generated on a queue. */
    public function onQueue(string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }

    /**
     * Store this media as an unbound draft: the row gets a generated `draft_token` and a TTL
     * `draft_expires_at`, with no owning model, until `MediaLibrary::for($model)->bindDraft()`
     * binds it.
     */
    public function asDraft(): self
    {
        $this->draft = true;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    public function withCustomProperties(array $properties): self
    {
        $this->customProperties = $properties;

        return $this;
    }

    public function withProperty(string $key, mixed $value): self
    {
        $this->customProperties[$key] = $value;

        return $this;
    }

    /** Terminal — model-bound media. */
    public function toMediaBucket(string $bucket = 'default', ?string $disk = null): Media
    {
        return $this->persist($bucket, $disk ?? $this->diskOverride);
    }

    public function toMediaBucketOnDisk(string $bucket, string $disk): Media
    {
        return $this->persist($bucket, $disk);
    }

    /** Terminal — global media (alias used by the Media facade). */
    public function toBucket(string $bucket = 'default', ?string $disk = null): Media
    {
        return $this->persist($bucket, $disk ?? $this->diskOverride);
    }

    private function persist(string $bucket, ?string $disk): Media
    {
        return $this->manager->store($this->snapshot($bucket, $disk));
    }

    private function snapshot(string $bucket, ?string $disk): PendingFileAddState
    {
        return new PendingFileAddState(
            owner: $this->owner,
            file: $this->file,
            bucket: $bucket,
            name: $this->name,
            fileName: $this->fileName,
            diskOverride: $disk,
            variantsDiskOverride: $this->variantsDiskOverride,
            visibility: $this->visibility,
            preserveOriginal: $this->preserveOriginal,
            customProperties: $this->customProperties,
            queue: $this->queue,
            draft: $this->draft,
        );
    }
}
