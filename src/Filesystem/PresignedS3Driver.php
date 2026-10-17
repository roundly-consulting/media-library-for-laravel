<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Filesystem;

use Aws\S3\S3Client;
use Closure;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Arr;
use LogicException;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/**
 * The factory behind the `presigned-s3` disk driver, registered by the service provider.
 *
 * A disk opts in by naming the driver; every `s3` key still applies. The stock disk is built by
 * the framework's own `createS3Driver()`, so credentials, region, path style, `options` and
 * `throw` stay Laravel's. Without `presign_endpoint` that stock adapter is returned unchanged;
 * with one it is wrapped in a {@see PresigningS3Adapter}.
 *
 * Nothing here runs at boot: the factory runs on the first `Storage::disk()` of a `presigned-s3`
 * disk, so a host without the S3 stack only hears about it when it uses the driver — as a
 * configuration error, not a missing-class fatal.
 *
 * @internal
 */
final class PresignedS3Driver
{
    public const string DRIVER = 'presigned-s3';

    /** The classes the S3 stack (league/flysystem-aws-s3-v3 + aws/aws-sdk-php) brings. */
    private const array S3_STACK = ['Aws\S3\S3Client', 'League\Flysystem\AwsS3V3\AwsS3V3Adapter'];

    /** Keys that must not reach the presign client: re-hosting, and secrets it never re-reads. */
    private const array NOT_FOR_PRESIGNING = [
        'url', 'temporary_url', 'presign_endpoint', 'presign_use_path_style_endpoint',
        'key', 'secret', 'token', 'credentials', 'handler', 'http_handler',
    ];

    /**
     * @param  (Closure(string): bool)|null  $classExists  replaces `class_exists()` (testing seam)
     */
    public function __construct(
        private readonly FilesystemManager $filesystem,
        private readonly ?Closure $classExists = null,
    ) {}

    /**
     * Build a `presigned-s3` disk from its configuration.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws InvalidConfigurationException when the S3 stack is missing, a presign key is unusable,
     *                                       or `temporary_url` is set next to `presign_endpoint`
     */
    public function create(array $config): AwsS3V3Adapter
    {
        $disk = $this->diskName($config);

        foreach (self::S3_STACK as $class) {
            if (! ($this->classExists ?? class_exists(...))($class)) {
                throw new InvalidConfigurationException(
                    "Configuration value [filesystems.disks.{$disk}.driver] names the presigned-s3 driver, which needs "
                    .'the S3 filesystem adapter: composer require league/flysystem-aws-s3-v3.'
                );
            }
        }

        $endpoint = self::presignEndpoint($config, $disk);
        $pathStyle = self::presignPathStyle($config, $disk);
        $rehost = $config['temporary_url'] ?? null;

        if ($endpoint !== null && $rehost !== null && (! is_string($rehost) || trim($rehost) !== '')) {
            // `temporary_url` re-hosts a URL after it is signed, so the signature would cover a host
            // the URL no longer names. Next to `presign_endpoint` it contradicts it.
            throw new InvalidConfigurationException(
                "Configuration value [filesystems.disks.{$disk}.temporary_url] must not be set together with "
                .'presign_endpoint: re-hosting a presigned URL breaks its signature.'
            );
        }

        $stock = $this->s3Disk($config);

        if ($endpoint === null) {
            return $stock;
        }

        $client = $stock->getClient();

        return new PresigningS3Adapter(
            $stock->getDriver(),
            $stock->getAdapter(),
            $stock->getConfig(),
            $client,
            $this->presignClient($config, $client, $endpoint, $pathStyle),
            $disk,
        );
    }

    /**
     * A second client that agrees with the operation client on everything but the endpoint: the
     * same config through the same `createS3Driver()`, the operation client's region and its own
     * credentials provider — never the keys re-read from config — and a handler that refuses to
     * send.
     *
     * @param  array<string, mixed>  $config
     */
    private function presignClient(array $config, S3Client $client, string $endpoint, ?bool $pathStyle): S3Client
    {
        $presign = Arr::except($config, self::NOT_FOR_PRESIGNING);
        $presign['endpoint'] = $endpoint;
        $presign['region'] = $client->getRegion();
        $presign['credentials'] = static fn (): mixed => $client->getCredentials();
        $presign['handler'] = static function (): never {
            throw new LogicException('The presign client never sends a request; presigning is local computation.');
        };

        if ($pathStyle !== null) {
            $presign['use_path_style_endpoint'] = $pathStyle;
        }

        return $this->s3Disk($presign)->getClient();
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function s3Disk(array $config): AwsS3V3Adapter
    {
        $disk = $this->filesystem->createS3Driver($config);

        if (! $disk instanceof AwsS3V3Adapter) {
            throw new LogicException('createS3Driver() returned '.$disk::class.', not an '.AwsS3V3Adapter::class.'.');
        }

        return $disk;
    }

    /**
     * The validated public origin without a trailing slash, or null when it is not set (off).
     *
     * Absolute `http`/`https` with a host, and nothing else: userinfo would put a secret into every
     * URL, and a path, query or fragment would be signed into every URL (an ingress that strips a
     * path prefix then breaks every signature). The message names the key, never the value.
     *
     * @param  array<string, mixed>  $config
     */
    private static function presignEndpoint(array $config, string $disk): ?string
    {
        $endpoint = $config['presign_endpoint'] ?? null;

        if ($endpoint === null || (is_string($endpoint) && trim($endpoint) === '')) {
            return null;
        }

        $parts = is_string($endpoint) && preg_match('/[\s?#@\\\\]/', $endpoint) !== 1 ? parse_url($endpoint) : false;

        if ($parts === false
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new InvalidConfigurationException(
                "Configuration value [filesystems.disks.{$disk}.presign_endpoint] must be an absolute http or https URL "
                .'with a host and no user info, path, query or fragment.'
            );
        }

        return rtrim($endpoint, '/');
    }

    /**
     * The public origin's own path-style switch, or null to inherit `use_path_style_endpoint`.
     *
     * @param  array<string, mixed>  $config
     */
    private static function presignPathStyle(array $config, string $disk): ?bool
    {
        $value = $config['presign_use_path_style_endpoint'] ?? null;

        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE)
            ?? throw new InvalidConfigurationException(
                "Configuration value [filesystems.disks.{$disk}.presign_use_path_style_endpoint] must be a boolean "
                .'(true/false, 1/0, on/off or yes/no).'
            );
    }

    /**
     * The name the disk is configured under, for messages. A custom driver is handed the disk's
     * config but not its name, so it is looked up; an on-demand disk (`Storage::build()`) has none
     * and reads as Laravel names it, `ondemand`.
     *
     * @param  array<string, mixed>  $config
     */
    private function diskName(array $config): string
    {
        $disks = config('filesystems.disks');

        foreach (is_array($disks) ? $disks : [] as $name => $candidate) {
            if ($candidate === $config) {
                return (string) $name;
            }
        }

        return 'ondemand';
    }
}
