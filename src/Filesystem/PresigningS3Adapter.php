<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Filesystem;

use Aws\S3\S3Client;
use DateTimeInterface;
use Illuminate\Filesystem\AwsS3V3Adapter;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemOperator;
use RoundlyConsulting\MediaLibrary\Exceptions\DiskHasNoPublicUrl;

/**
 * A `presigned-s3` disk with a `presign_endpoint`: an S3 disk with two endpoints.
 *
 * Every read and write goes to `endpoint` (an in-cluster object store such as
 * `http://rustfs:9000`), exactly as on Laravel's `s3` driver. Presigned URLs are signed by a
 * second client whose endpoint IS the public origin browsers reach (`presign_endpoint`, e.g.
 * `https://media.example.com`).
 *
 * Why a second client rather than a rewrite: SigV4 signs the host and the path, so a URL signed
 * for `rustfs:9000` and re-hosted afterwards — all Laravel's `temporary_url` option does — carries
 * a signature the object store refuses. The presign client is built by Laravel's own
 * `createS3Driver()` from the disk's config with only the endpoint swapped, and borrows the
 * operation client's credentials provider, so both always sign as the same identity (a session
 * token included). Presigning is local computation: the presign client never sends a request,
 * and its handler throws if anything ever tries.
 *
 * Built by {@see PresignedS3Driver}; without `presign_endpoint` a `presigned-s3` disk is Laravel's
 * stock {@see AwsS3V3Adapter} instead.
 */
final class PresigningS3Adapter extends AwsS3V3Adapter
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        FilesystemOperator $driver,
        FilesystemAdapter $adapter,
        array $config,
        S3Client $client,
        private readonly S3Client $presignClient,
        private readonly string $disk,
    ) {
        parent::__construct($driver, $adapter, $config, $client);
    }

    /** Whether URLs are signed for a separate public origin — always, on this adapter. */
    public function presignsSeparately(): bool
    {
        return true;
    }

    /** The client that signs URLs for the public origin. It never sends a request. */
    public function getPresignClient(): S3Client
    {
        return $this->presignClient;
    }

    /**
     * A permanent, unsigned URL — only from an explicit `url` on the disk.
     *
     * Without one, the stock implementation builds the URL from the operation `endpoint`: the
     * in-cluster host, which resolves nowhere outside the cluster and would leak into responses.
     *
     * @param  string  $path
     *
     * @throws DiskHasNoPublicUrl when the disk sets no `url`
     */
    public function url($path): string
    {
        $url = $this->config['url'] ?? null;

        if (! is_string($url) || trim($url) === '') {
            throw DiskHasNoPublicUrl::presignedSeparately($this->disk);
        }

        return parent::url($path);
    }

    /**
     * A presigned GET URL, signed for the public origin. No `temporary_url` re-hosting: it would
     * break the signature (the driver refuses that combination when it builds the disk).
     *
     * @param  string  $path
     * @param  DateTimeInterface  $expiration
     * @param  array<string, mixed>  $options
     */
    public function temporaryUrl($path, $expiration, array $options = []): string
    {
        $command = $this->presignClient->getCommand('GetObject', array_merge([
            'Bucket' => $this->config['bucket'],
            'Key' => $this->prefixer->prefixPath($path),
        ], $options));

        return (string) $this->presignClient->createPresignedRequest($command, $expiration, $options)->getUri();
    }

    /**
     * A presigned PUT URL, signed for the public origin, in the framework's `{url, headers}` shape.
     *
     * @param  string  $path
     * @param  DateTimeInterface  $expiration
     * @param  array<string, mixed>  $options
     * @return array{url: string, headers: array<array<string>>}
     */
    public function temporaryUploadUrl($path, $expiration, array $options = []): array
    {
        $command = $this->presignClient->getCommand('PutObject', array_merge([
            'Bucket' => $this->config['bucket'],
            'Key' => $this->prefixer->prefixPath($path),
        ], $options));

        $signed = $this->presignClient->createPresignedRequest($command, $expiration, $options);

        return [
            'url' => (string) $signed->getUri(),
            'headers' => $signed->getHeaders(),
        ];
    }
}
