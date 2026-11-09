<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Uploads;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\DirectUpload;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;
use RoundlyConsulting\MediaLibrary\Support\MediaConfig;

/**
 * Fluent builder for a direct upload: the browser sends the file straight to storage, and
 * `MediaLibrary::adoptUpload($token)` turns it into media afterwards. The terminal
 * `toMediaBucket()` / `toBucket()` signs the upload and records its ticket.
 *
 * Everything the browser declares here (name, size, type, checksum) is only what gets signed;
 * adoption checks the stored bytes themselves against the bucket's rules.
 */
final class PendingDirectUpload
{
    private ?string $declaredType = null;

    private ?string $sha256 = null;

    private bool $multipart = false;

    private ?int $partSize = null;

    private ?Model $actor = null;

    private ?string $name = null;

    /** @var array<string, mixed> */
    private array $customProperties = [];

    private ?string $queue = null;

    private ?int $expiresIn = null;

    private ?string $visibility = null;

    private ?string $diskOverride = null;

    private bool $draft = false;

    /**
     * @internal built by {@see MediaLibraryManager} — start one with `MediaLibrary::upload()` or
     *           `MediaLibrary::for($model)->upload()`
     *
     * @throws InvalidArgumentException for a size below 1 byte
     */
    public function __construct(
        private readonly MediaLibraryManager $manager,
        private readonly HasMedia|Model|null $owner,
        private readonly string $fileName,
        private readonly int $size,
    ) {
        if ($size < 1) {
            throw new InvalidArgumentException("size must be at least 1 byte, {$size} given.");
        }
    }

    /** The type the browser reports — checked against the bucket early, for a friendly refusal only. */
    public function declaringType(string $mimeType): self
    {
        $this->declaredType = strtolower(trim($mimeType));

        return $this;
    }

    /**
     * The file's sha256 (hex) the browser computed. On S3 a `put` then signs it, so storage
     * refuses any other bytes — and private media may be uploaded in place.
     *
     * @throws InvalidArgumentException for anything but 64 hex digits
     */
    public function withSha256(string $sha256): self
    {
        if (preg_match('/^[0-9a-f]{64}$/i', $sha256) !== 1) {
            throw new InvalidArgumentException('sha256 must be 64 hexadecimal digits.');
        }

        $this->sha256 = strtolower($sha256);

        return $this;
    }

    /** Upload in parts (S3 only); `$partSize` in bytes, else `media.direct_uploads.multipart.part_size`. */
    public function multipart(?int $partSize = null): self
    {
        if ($partSize !== null && $partSize < 5 * 1024 * 1024) {
            throw new InvalidArgumentException('partSize must be at least 5 MiB.');
        }

        $this->multipart = true;
        $this->partSize = $partSize;

        return $this;
    }

    /** Bind the ticket to an actor: adopting it then requires the same actor. */
    public function forActor(Model $actor): self
    {
        $this->actor = $actor;

        return $this;
    }

    public function usingName(string $name): self
    {
        $this->name = $name;

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

    /** Queue for the adopted media's variants. */
    public function onQueue(string $queue): self
    {
        $this->queue = $queue;

        return $this;
    }

    /** Minutes the ticket and its URLs live (at least 1); default `media.direct_uploads.ttl`. */
    public function expiresIn(int $minutes): self
    {
        if ($minutes < 1) {
            throw new InvalidArgumentException("expiresIn must be at least 1 minute, {$minutes} given.");
        }

        $this->expiresIn = $minutes;

        return $this;
    }

    /** `public` or `private`; anything else throws. */
    public function withVisibility(string $visibility): self
    {
        $this->visibility = MediaConfig::visibility($visibility);

        return $this;
    }

    public function useDisk(string $disk): self
    {
        $this->diskOverride = $disk;

        return $this;
    }

    /** Adopt as an unbound draft, bound later with `MediaLibrary::for($model)->bindDraft()`. */
    public function asDraft(): self
    {
        $this->draft = true;

        return $this;
    }

    /** Terminal — sign the upload into an owner's bucket. */
    public function toMediaBucket(string $bucket = 'default'): DirectUpload
    {
        return $this->manager->issueUpload($this->snapshot($bucket));
    }

    /** Terminal — global media. */
    public function toBucket(string $bucket = 'default'): DirectUpload
    {
        return $this->toMediaBucket($bucket);
    }

    private function snapshot(string $bucket): DirectUploadState
    {
        return new DirectUploadState(
            owner: $this->owner,
            bucket: $bucket,
            fileName: $this->fileName,
            size: $this->size,
            declaredType: $this->declaredType,
            sha256: $this->sha256,
            multipart: $this->multipart,
            partSize: $this->partSize,
            actor: $this->actor,
            name: $this->name,
            customProperties: $this->customProperties,
            queue: $this->queue,
            expiresIn: $this->expiresIn,
            visibility: $this->visibility,
            diskOverride: $this->diskOverride,
            draft: $this->draft,
        );
    }
}
