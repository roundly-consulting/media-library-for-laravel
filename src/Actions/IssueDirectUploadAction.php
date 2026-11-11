<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RoundlyConsulting\MediaLibrary\Buckets\BucketGuard;
use RoundlyConsulting\MediaLibrary\Buckets\BucketValidationRules;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\DirectUpload;
use RoundlyConsulting\MediaLibrary\Enums\DirectUploadMethod;
use RoundlyConsulting\MediaLibrary\Enums\DirectUploadStatus;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadsNotSupported;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\MediaLibrary\Exceptions\TooManyPendingDirectUploads;
use RoundlyConsulting\MediaLibrary\Filesystem\S3UploadSigner;
use RoundlyConsulting\MediaLibrary\Models\MediaUpload;
use RoundlyConsulting\MediaLibrary\Support\DiskResolver;
use RoundlyConsulting\MediaLibrary\Support\FileNames;
use RoundlyConsulting\MediaLibrary\Support\MediaConfig;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;
use RoundlyConsulting\MediaLibrary\Support\StoredFiles;
use RoundlyConsulting\MediaLibrary\Uploads\DirectUploadState;
use RoundlyConsulting\MediaLibrary\Uploads\StoredObjects;
use RoundlyConsulting\MediaLibrary\Uploads\UploadToken;

/**
 * Signs a direct upload and records its ticket: `MediaLibrary::upload(...)->toBucket()`.
 *
 * Refused before anything is signed: direct uploads off, an unsaved owner, a declared size over
 * the bucket's cap, a declared type the bucket does not accept, too many pending tickets for the
 * owner, a disk that cannot sign what is asked. The key is minted by the server (a ULID under the
 * private staging prefix) — the browser never chooses where its bytes land. Private media whose
 * bytes cannot change after the check (multipart, or a PUT that signed its sha256) upload in
 * place, straight to their final key.
 */
final class IssueDirectUploadAction
{
    public function __construct(
        private readonly BucketGuard $guard,
        private readonly DiskResolver $disks,
        private readonly StoredObjects $objects,
        private readonly StoredFiles $files,
        private readonly S3UploadSigner $s3,
    ) {}

    public function execute(DirectUploadState $state): DirectUpload
    {
        if (! MediaConfig::directUploadsEnabled()) {
            throw DirectUploadsNotSupported::disabled();
        }

        if (! $state->draft) {
            $this->guard->ensureSavedOwner($state->owner);
        }

        $bucket = $this->guard->bucketFor($state->owner, $state->bucket);
        $cap = BucketValidationRules::maxFileSizeFor($bucket);

        if ($cap !== null && $state->size > $cap) {
            throw FileUnacceptableForBucket::tooLarge($state->size, $cap, $state->bucket);
        }

        if ($state->declaredType !== null && ! BucketValidationRules::acceptsMimeType($bucket, $state->declaredType)) {
            throw FileUnacceptableForBucket::mimeType($state->declaredType, $state->bucket);
        }

        $owner = $state->draft || ! $state->owner instanceof Model ? null : $state->owner;
        $this->ensureQuota($owner, $state->actor);

        $disk = $this->disks->resolveOriginalDisk($state->diskOverride, $bucket);
        $visibility = $state->visibility ?? $bucket?->getVisibility() ?? MediaConfig::defaultVisibility();
        $staging = MediaConfig::stagingDisk() ?? $disk;
        $this->disks->ensureDiskExists($staging);

        if (! $this->objects->canMove($staging, $disk)) {
            throw DirectUploadsNotSupported::crossDisk($staging, $disk);
        }

        $method = $this->method($state, $staging);
        $s3 = $this->objects->s3($staging);
        $sha256 = $s3 !== null && $method === DirectUploadMethod::Put ? $state->sha256 : null;
        $inPlace = $visibility === MediaConfig::VISIBILITY_PRIVATE
            && $staging === $disk
            && ($method === DirectUploadMethod::Multipart || $sha256 !== null);

        $id = strtolower((string) Str::ulid());
        $uuid = (string) Str::uuid();
        $secret = UploadToken::secret();
        $expiresAt = CarbonImmutable::now()->addMinutes($state->expiresIn ?? MediaConfig::uploadTtl())->startOfSecond();
        $fileName = FileNames::sanitize($state->fileName);
        $path = $inPlace ? $this->finalPath($uuid, $owner, $state, $fileName, $disk) : MediaConfig::stagingPrefix().$id;

        $url = '';
        $headers = [];
        $fields = [];
        $uploadId = null;
        $partSize = null;
        $partCount = null;

        switch ($method) {
            case DirectUploadMethod::Put:
                ['url' => $url, 'headers' => $headers] = $s3 !== null
                    ? $this->s3->presignPut($s3, $path, $expiresAt, $sha256)
                    : $this->presignPut($staging, $path, $expiresAt);
                break;

            case DirectUploadMethod::Post:
                ['url' => $url, 'fields' => $fields] = $this->s3->presignPost($this->requireS3($staging), $path, $expiresAt, $state->size, $state->declaredType);
                break;

            case DirectUploadMethod::Multipart:
                $partSize = $state->partSize ?? MediaConfig::multipartPartSize();
                $partCount = (int) ceil($state->size / $partSize);

                if ($partCount > MediaConfig::multipartMaxParts()) {
                    throw DirectUploadsNotSupported::tooManyParts($partCount, MediaConfig::multipartMaxParts());
                }

                $uploadId = $this->s3->createMultipart($this->requireS3($staging), $path);
                break;
        }

        MediaUpload::query()->create([
            'id' => $id,
            'token_hash' => UploadToken::digest($secret),
            'media_uuid' => $uuid,
            'disk' => $staging,
            'path' => $path,
            'in_place' => $inPlace,
            'bucket_name' => $state->bucket,
            'model_type' => $owner?->getMorphClass(),
            'model_id' => $owner === null ? null : (string) $owner->getKey(),
            'draft' => $state->draft,
            'actor_type' => $state->actor?->getMorphClass(),
            'actor_id' => $state->actor === null ? null : (string) $state->actor->getKey(),
            'file_name' => mb_substr($state->fileName, 0, 255),
            'declared_size' => $state->size,
            'declared_mime' => $state->declaredType,
            'declared_checksum' => $state->sha256,
            'max_size' => $state->size,
            'visibility' => $visibility,
            'options' => array_filter([
                'method' => $method === DirectUploadMethod::Multipart ? null : $method->value,
                'media_disk' => $disk,
                'name' => $state->name,
                'custom_properties' => $state->customProperties === [] ? null : $state->customProperties,
                'queue' => $state->queue,
            ], static fn (mixed $value): bool => $value !== null),
            'multipart_upload_id' => $uploadId,
            'part_size' => $partSize,
            'part_count' => $partCount,
            'status' => DirectUploadStatus::Pending,
            'expires_at' => $expiresAt,
        ]);

        return new DirectUpload(
            token: UploadToken::compose($id, $secret),
            method: $method,
            url: $url,
            headers: $headers,
            fields: $fields,
            key: $path,
            maxSize: $state->size,
            sizeEnforcedBySignature: $method === DirectUploadMethod::Post || $sha256 !== null,
            expiresAt: $expiresAt,
            mediaUuid: $uuid,
            partSize: $partSize,
            partCount: $partCount,
        );
    }

    private function method(DirectUploadState $state, string $disk): DirectUploadMethod
    {
        if ($state->multipart) {
            return DirectUploadMethod::Multipart;
        }

        return MediaConfig::uploadMethod($disk) === 'post' ? DirectUploadMethod::Post : DirectUploadMethod::Put;
    }

    private function requireS3(string $disk): AwsS3V3Adapter
    {
        return $this->objects->s3($disk) ?? throw DirectUploadsNotSupported::forDisk($disk, 'sign POST policies or multipart uploads (S3 only)');
    }

    /**
     * @return array{url: string, headers: array<string, string>}
     */
    private function presignPut(string $disk, string $path, CarbonImmutable $expiresAt): array
    {
        $storage = Storage::disk($disk);

        if (! $storage->providesTemporaryUploadUrls()) {
            throw DirectUploadsNotSupported::forDisk($disk, 'sign upload URLs');
        }

        $signed = $storage->temporaryUploadUrl($path, $expiresAt);
        $headers = [];

        foreach ((array) ($signed['headers'] ?? []) as $name => $value) {
            if (strcasecmp((string) $name, 'host') !== 0) {
                $headers[(string) $name] = is_array($value) ? implode(', ', array_map(strval(...), $value)) : (string) $value;
            }
        }

        return ['url' => (string) $signed['url'], 'headers' => $headers];
    }

    /** The free path the media will own — for an in-place upload, the key the browser writes. */
    private function finalPath(string $uuid, ?Model $owner, DirectUploadState $state, string $fileName, string $disk): string
    {
        $media = MediaModel::new();
        $media->uuid = $uuid;
        $media->bucket_name = $state->bucket;
        $media->file_name = $fileName;
        $media->disk = $disk;
        $media->model_type = $owner?->getMorphClass();
        $media->model_id = $owner?->getKey();

        return $this->files->freePath($media, $disk);
    }

    private function ensureQuota(?Model $owner, ?Model $actor): void
    {
        $holder = $owner ?? $actor;

        if ($holder === null) {
            return;
        }

        $column = $owner !== null ? 'model' : 'actor';
        $max = MediaConfig::maxPendingUploads();

        $pending = MediaUpload::query()
            ->where("{$column}_type", $holder->getMorphClass())
            ->where("{$column}_id", (string) $holder->getKey())
            ->where('status', DirectUploadStatus::Pending->value)
            ->where('expires_at', '>', CarbonImmutable::now())
            ->count();

        if ($pending >= $max) {
            throw TooManyPendingDirectUploads::make($max, $holder);
        }
    }
}
