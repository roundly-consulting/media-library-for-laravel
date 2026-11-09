<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Filesystem;

use Aws\S3\ObjectCopier;
use Aws\S3\PostObjectV4;
use Aws\S3\S3Client;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Filesystem\AwsS3V3Adapter;

/**
 * Every S3 call direct uploads make — the only place outside the `presigned-s3` driver that
 * touches the AWS SDK, which the host's `league/flysystem-aws-s3-v3` brings (the package never
 * requires it). Callers reach it only for a disk that is an {@see AwsS3V3Adapter}.
 *
 * Presigning (PUT, POST policy, multipart parts) uses the disk's presign client, so on a
 * `presigned-s3` disk URLs are signed for the public origin; every request that is sent (multipart
 * create/list/complete/abort, ranged reads, copies) goes to the operation client.
 *
 * @internal
 */
final class S3UploadSigner
{
    /** S3's largest single-request copy; anything bigger is copied in parts. */
    private const int SINGLE_COPY_LIMIT = 5 * 1024 * 1024 * 1024;

    /**
     * A presigned PUT. With `$sha256` (hex) the URL signs `x-amz-checksum-sha256`, so S3 refuses
     * any body with other bytes.
     *
     * @return array{url: string, headers: array<string, string>}
     */
    public function presignPut(AwsS3V3Adapter $disk, string $path, DateTimeInterface $expiry, ?string $sha256 = null): array
    {
        $options = $sha256 === null ? [] : ['ChecksumSHA256' => self::base64Digest($sha256)];
        $signed = $disk->temporaryUploadUrl($path, $expiry, $options);

        return ['url' => (string) $signed['url'], 'headers' => self::flatHeaders($signed['headers'])];
    }

    /**
     * A presigned POST policy: the key is fixed, the size is capped by `content-length-range`, and
     * a declared type is bound — the only signed size cap S3 offers.
     *
     * @return array{url: string, fields: array<string, string>}
     */
    public function presignPost(AwsS3V3Adapter $disk, string $path, CarbonInterface $expiry, int $maxSize, ?string $contentType): array
    {
        $bucket = $this->bucket($disk);
        $key = $disk->path($path);
        $inputs = ['key' => $key];
        $conditions = [['bucket' => $bucket], ['eq', '$key', $key], ['content-length-range', 1, $maxSize]];

        if ($contentType !== null) {
            $inputs['Content-Type'] = $contentType;
            $conditions[] = ['eq', '$Content-Type', $contentType];
        }

        $post = new PostObjectV4($this->presignClient($disk), $bucket, $inputs, $conditions, $expiry->getTimestamp());

        $fields = [];

        foreach ($post->getFormInputs() as $name => $value) {
            $fields[(string) $name] = (string) $value;
        }

        return ['url' => (string) $post->getFormAttributes()['action'], 'fields' => $fields];
    }

    /** Start a multipart upload at `$path`; returns its upload id. */
    public function createMultipart(AwsS3V3Adapter $disk, string $path): string
    {
        $result = $disk->getClient()->createMultipartUpload([
            'Bucket' => $this->bucket($disk),
            'Key' => $disk->path($path),
        ]);

        return (string) $result['UploadId'];
    }

    /**
     * A presigned PUT for one part.
     *
     * @return array{url: string, headers: array<string, string>}
     */
    public function presignPart(AwsS3V3Adapter $disk, string $path, string $uploadId, int $partNumber, DateTimeInterface $expiry): array
    {
        $client = $this->presignClient($disk);
        $command = $client->getCommand('UploadPart', [
            'Bucket' => $this->bucket($disk),
            'Key' => $disk->path($path),
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
        ]);

        $request = $client->createPresignedRequest($command, $expiry);
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            $headers[(string) $name] = $values;
        }

        return ['url' => (string) $request->getUri(), 'headers' => self::flatHeaders($headers)];
    }

    /**
     * The parts uploaded so far, by part number.
     *
     * @return array<int, StoredPart>
     */
    public function listParts(AwsS3V3Adapter $disk, string $path, string $uploadId): array
    {
        $parts = [];
        $marker = null;

        do {
            $result = $disk->getClient()->listParts(array_filter([
                'Bucket' => $this->bucket($disk),
                'Key' => $disk->path($path),
                'UploadId' => $uploadId,
                'PartNumberMarker' => $marker,
            ], static fn (mixed $value): bool => $value !== null));

            foreach ((array) ($result['Parts'] ?? []) as $part) {
                $number = (int) $part['PartNumber'];
                $parts[$number] = new StoredPart($number, (int) $part['Size'], trim((string) $part['ETag'], '"'));
            }

            $marker = ($result['IsTruncated'] ?? false) ? $result['NextPartNumberMarker'] : null;
        } while ($marker !== null);

        ksort($parts);

        return $parts;
    }

    /**
     * @param  list<StoredPart>  $parts
     */
    public function completeMultipart(AwsS3V3Adapter $disk, string $path, string $uploadId, array $parts): void
    {
        $disk->getClient()->completeMultipartUpload([
            'Bucket' => $this->bucket($disk),
            'Key' => $disk->path($path),
            'UploadId' => $uploadId,
            'MultipartUpload' => [
                'Parts' => array_map(static fn (StoredPart $part): array => ['PartNumber' => $part->number, 'ETag' => '"'.$part->etag.'"'], $parts),
            ],
        ]);
    }

    public function abortMultipart(AwsS3V3Adapter $disk, string $path, string $uploadId): void
    {
        $disk->getClient()->abortMultipartUpload([
            'Bucket' => $this->bucket($disk),
            'Key' => $disk->path($path),
            'UploadId' => $uploadId,
        ]);
    }

    /**
     * Abort every multipart upload under `$prefix` started before `$before` that no live ticket
     * holds. Returns how many it aborted.
     *
     * @param  list<string>  $keep  upload ids of live tickets
     */
    public function abortStaleMultipartUploads(AwsS3V3Adapter $disk, string $prefix, DateTimeInterface $before, array $keep): int
    {
        $aborted = 0;
        $keyMarker = null;
        $idMarker = null;

        do {
            $result = $disk->getClient()->listMultipartUploads(array_filter([
                'Bucket' => $this->bucket($disk),
                'Prefix' => $disk->path($prefix),
                'KeyMarker' => $keyMarker,
                'UploadIdMarker' => $idMarker,
            ], static fn (mixed $value): bool => $value !== null));

            foreach ((array) ($result['Uploads'] ?? []) as $upload) {
                $initiated = $upload['Initiated'] ?? null;

                if (in_array((string) $upload['UploadId'], $keep, true)
                    || ! $initiated instanceof DateTimeInterface
                    || $initiated >= $before) {
                    continue;
                }

                $disk->getClient()->abortMultipartUpload([
                    'Bucket' => $this->bucket($disk),
                    'Key' => (string) $upload['Key'],
                    'UploadId' => (string) $upload['UploadId'],
                ]);
                $aborted++;
            }

            $truncated = (bool) ($result['IsTruncated'] ?? false);
            $keyMarker = $truncated ? $result['NextKeyMarker'] : null;
            $idMarker = $truncated ? $result['NextUploadIdMarker'] : null;
        } while ($keyMarker !== null);

        return $aborted;
    }

    /** The first `$length` bytes of an object — one ranged GET, never the whole object. */
    public function readHead(AwsS3V3Adapter $disk, string $path, int $length): string
    {
        $result = $disk->getClient()->getObject([
            'Bucket' => $this->bucket($disk),
            'Key' => $disk->path($path),
            'Range' => 'bytes=0-'.max(0, $length - 1),
        ]);

        return (string) $result['Body'];
    }

    /** The sha256 the store recorded for an object (hex), or null when it has none. */
    public function storedSha256(AwsS3V3Adapter $disk, string $path): ?string
    {
        $result = $disk->getClient()->headObject([
            'Bucket' => $this->bucket($disk),
            'Key' => $disk->path($path),
            'ChecksumMode' => 'ENABLED',
        ]);

        $digest = $result['ChecksumSHA256'] ?? null;

        // A multipart object's checksum is a checksum of checksums ("…-3"), never the file's.
        if (! is_string($digest) || str_contains($digest, '-')) {
            return null;
        }

        $raw = base64_decode($digest, true);

        return $raw === false || strlen($raw) !== 32 ? null : bin2hex($raw);
    }

    /**
     * Copy an object inside the store with fresh metadata — the sniffed type and the response
     * disposition — and the media's visibility. Bigger than 5 GiB, it is copied in parts.
     */
    public function copy(
        AwsS3V3Adapter $from,
        string $fromPath,
        AwsS3V3Adapter $to,
        string $toPath,
        int $size,
        ?string $contentType,
        string $contentDisposition,
        string $visibility,
    ): void {
        $metadata = array_filter([
            'ContentType' => $contentType,
            'ContentDisposition' => $contentDisposition,
        ], static fn (?string $value): bool => $value !== null);
        $acl = $visibility === 'public' ? 'public-read' : 'private';
        $source = ['Bucket' => $this->bucket($from), 'Key' => $from->path($fromPath)];
        $destination = ['Bucket' => $this->bucket($to), 'Key' => $to->path($toPath)];

        if ($size <= self::SINGLE_COPY_LIMIT) {
            $to->getClient()->copyObject($destination + $metadata + [
                'CopySource' => rawurlencode($source['Bucket']).'/'.str_replace('%2F', '/', rawurlencode($source['Key'])),
                'MetadataDirective' => 'REPLACE',
                'ACL' => $acl,
            ]);

            return;
        }

        (new ObjectCopier($to->getClient(), $source, $destination, $acl, [
            'params' => $metadata,
            'metadata_directive' => 'REPLACE',
        ]))->copy();
    }

    private function bucket(AwsS3V3Adapter $disk): string
    {
        return (string) ($disk->getConfig()['bucket'] ?? '');
    }

    private function presignClient(AwsS3V3Adapter $disk): S3Client
    {
        return $disk instanceof PresigningS3Adapter ? $disk->getPresignClient() : $disk->getClient();
    }

    /** The binary digest S3's checksum fields take, base64-encoded. */
    private static function base64Digest(string $hex): string
    {
        return base64_encode((string) hex2bin($hex));
    }

    /**
     * Signed request headers as one value each, minus `Host` (the browser sets it).
     *
     * @param  array<array-key, mixed>  $headers
     * @return array<string, string>
     */
    private static function flatHeaders(array $headers): array
    {
        $flat = [];

        foreach ($headers as $name => $values) {
            if (strcasecmp((string) $name, 'host') === 0) {
                continue;
            }

            $flat[(string) $name] = is_array($values) ? implode(', ', array_map(strval(...), $values)) : (string) $values;
        }

        return $flat;
    }
}
