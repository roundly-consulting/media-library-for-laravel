<?php

declare(strict_types=1);

use Aws\Credentials\Credentials;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use Aws\Signature\S3SignatureV4;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Exceptions\DiskHasNoPublicUrl;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Filesystem\PresignedS3Driver;
use RoundlyConsulting\MediaLibrary\Filesystem\PresigningS3Adapter;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/*
 * The `presigned-s3` disk driver: reads and writes go to the in-cluster object store, presigned
 * URLs are signed for the public origin browsers reach. Presigning is local computation, so all
 * of this runs on real S3 clients with no network: the operation client answers from an AWS SDK
 * MockHandler (an empty queue fails any call it was not told to expect).
 */

const PRESIGN_START = 1_760_000_000;

/**
 * Configure (and build) a disk. The operation client's MockHandler is left on test()->operation.
 *
 * @param  array<string, mixed>  $overrides  null values remove the key
 */
function presignedDisk(array $overrides = [], string $name = 'media-s3'): FilesystemAdapter
{
    test()->operation = new MockHandler;

    $config = array_merge([
        'driver' => 'presigned-s3',
        'key' => 'AKIAOPERATION',
        'secret' => 'operation-secret',
        'region' => 'eu-central-1',
        'bucket' => 'media',
        'endpoint' => 'http://rustfs:9000',
        'use_path_style_endpoint' => true,
        'presign_endpoint' => 'https://media.example.com',
        'visibility' => 'private',
        'handler' => test()->operation,
    ], $overrides);

    config()->set("filesystems.disks.{$name}", array_filter($config, static fn (mixed $value): bool => $value !== null));
    Storage::forgetDisk($name);

    return Storage::disk($name);
}

/** @return array<string, string> */
function presignQuery(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query;
}

/**
 * Re-sign `$url` from scratch with the SDK's own signer, as if the request arrived at
 * `$host`, and say whether it yields the URL's signature. The object store does exactly this.
 */
function signatureVerifiesAt(string $url, string $method, string $scheme, string $host, ?int $port, Credentials $credentials): bool
{
    $query = presignQuery($url);
    $start = (new DateTimeImmutable($query['X-Amz-Date']))->getTimestamp();
    $bare = (new Uri($url))
        ->withQuery(http_build_query(array_filter($query, static fn (string $key): bool => ! str_starts_with($key, 'X-Amz-'), ARRAY_FILTER_USE_KEY)))
        ->withScheme($scheme)
        ->withHost($host)
        ->withPort($port);

    $resigned = (new S3SignatureV4('s3', 'eu-central-1'))->presign(
        new Request($method, $bare),
        $credentials,
        $start + (int) $query['X-Amz-Expires'],
        ['start_time' => $start],
    );

    return presignQuery((string) $resigned->getUri())['X-Amz-Signature'] === $query['X-Amz-Signature'];
}

function presignExpiry(): DateTimeImmutable
{
    return (new DateTimeImmutable)->setTimestamp(PRESIGN_START + 600);
}

it('is the stock s3 adapter, byte for byte, without a presign endpoint', function (): void {
    $disk = presignedDisk(['presign_endpoint' => null]);
    $stock = presignedDisk(['driver' => 's3', 'presign_endpoint' => null], 'stock-s3');

    $url = $disk->temporaryUrl('a/b.txt', presignExpiry(), ['start_time' => PRESIGN_START]);

    expect($disk)->toBeInstanceOf(AwsS3V3Adapter::class)
        ->not->toBeInstanceOf(PresigningS3Adapter::class)
        ->and($url)->toBe($stock->temporaryUrl('a/b.txt', presignExpiry(), ['start_time' => PRESIGN_START]))
        ->and($url)->toStartWith('http://rustfs:9000/media/a/b.txt?');
});

it('signs temporary urls for the public origin', function (): void {
    $disk = presignedDisk();

    $url = $disk->temporaryUrl('photos/a.jpg', presignExpiry(), ['start_time' => PRESIGN_START]);
    $query = presignQuery($url);

    expect($disk)->toBeInstanceOf(PresigningS3Adapter::class)
        ->and($disk->presignsSeparately())->toBeTrue()
        ->and($url)->toStartWith('https://media.example.com/media/photos/a.jpg?')
        ->and($url)->not->toContain('rustfs')
        ->and($query['X-Amz-SignedHeaders'])->toBe('host')
        ->and($query['X-Amz-Credential'])->toStartWith('AKIAOPERATION/')
        ->and(signatureVerifiesAt($url, 'GET', 'https', 'media.example.com', null, new Credentials('AKIAOPERATION', 'operation-secret')))->toBeTrue()
        ->and(signatureVerifiesAt($url, 'GET', 'http', 'rustfs', 9000, new Credentials('AKIAOPERATION', 'operation-secret')))->toBeFalse();
});

it('keeps the public origin port and the disk root in the signed url', function (): void {
    $disk = presignedDisk(['presign_endpoint' => 'http://media.localhost:8080/', 'root' => 'tenant-1']);

    $url = $disk->temporaryUrl('a.jpg', presignExpiry(), ['start_time' => PRESIGN_START]);

    expect($url)->toStartWith('http://media.localhost:8080/media/tenant-1/a.jpg?')
        ->and(signatureVerifiesAt($url, 'GET', 'http', 'media.localhost', 8080, new Credentials('AKIAOPERATION', 'operation-secret')))->toBeTrue();
});

it('signs upload urls for the public origin in the framework shape', function (): void {
    $disk = presignedDisk();

    $upload = $disk->temporaryUploadUrl('incoming/b.png', presignExpiry(), ['start_time' => PRESIGN_START]);

    expect($upload)->toHaveKeys(['url', 'headers'])
        ->and($upload['url'])->toStartWith('https://media.example.com/media/incoming/b.png?')
        ->and($upload['url'])->not->toContain('rustfs')
        ->and($upload['headers'])->toBeArray()
        ->and(signatureVerifiesAt($upload['url'], 'PUT', 'https', 'media.example.com', null, new Credentials('AKIAOPERATION', 'operation-secret')))->toBeTrue();
});

it('reads and writes through the in-cluster endpoint', function (): void {
    $disk = presignedDisk();
    test()->operation->append(new Result([]), new Result(['Body' => Utils::streamFor('stored bytes')]));

    expect($disk->put('a.txt', 'stored bytes'))->toBeTrue();
    $put = test()->operation->getLastRequest();

    expect($disk->get('a.txt'))->toBe('stored bytes');
    $get = test()->operation->getLastRequest();

    expect($put->getMethod())->toBe('PUT')
        ->and($put->getUri()->getHost())->toBe('rustfs')
        ->and($put->getUri()->getPort())->toBe(9000)
        ->and($get->getMethod())->toBe('GET')
        ->and((string) $get->getUri())->toStartWith('http://rustfs:9000/media/a.txt');
});

it('never sends a request through the presign client', function (): void {
    $disk = presignedDisk();

    expect($disk->temporaryUrl('a.txt', presignExpiry()))->toStartWith('https://media.example.com/')
        ->and(fn () => $disk->getPresignClient()->headObject(['Bucket' => 'media', 'Key' => 'a.txt']))
        ->toThrow(LogicException::class, 'never sends');
});

it('presigns with the operation client credentials, session token included', function (): void {
    $disk = presignedDisk(['key' => 'ASIATEMPORARY', 'secret' => 'temporary-secret', 'token' => 'session-token-1']);

    $url = $disk->temporaryUrl('a.txt', presignExpiry(), ['start_time' => PRESIGN_START]);
    $query = presignQuery($url);

    expect($query['X-Amz-Credential'])->toStartWith('ASIATEMPORARY/')
        ->and($query['X-Amz-Security-Token'])->toBe('session-token-1')
        ->and($disk->getPresignClient()->getCredentials()->wait()->getAccessKeyId())->toBe('ASIATEMPORARY')
        ->and(signatureVerifiesAt($url, 'GET', 'https', 'media.example.com', null, new Credentials('ASIATEMPORARY', 'temporary-secret', 'session-token-1')))->toBeTrue();
});

it('agrees with the operation client on region and the client options laravel derives', function (): void {
    $disk = presignedDisk(['region' => 'us-west-2', 'use_path_style_endpoint' => false, 'bucket_endpoint' => true]);

    expect($disk->getPresignClient()->getRegion())->toBe('us-west-2')
        ->and($disk->getPresignClient())->not->toBe($disk->getClient())
        ->and($disk->getPresignClient())->toBeInstanceOf(S3Client::class)
        // bucket_endpoint: the endpoint already names the bucket, on both clients.
        ->and($disk->temporaryUrl('a.txt', presignExpiry()))->toStartWith('https://media.example.com/a.txt?');
});

it('presigns virtual-hosted or path style as configured', function (array $overrides, string $prefix): void {
    $disk = presignedDisk($overrides);

    expect($disk->temporaryUrl('a.txt', presignExpiry()))->toStartWith($prefix);
})->with([
    'inherits path style' => [['use_path_style_endpoint' => true], 'https://media.example.com/media/a.txt?'],
    'inherits virtual hosts' => [['use_path_style_endpoint' => false], 'https://media.media.example.com/a.txt?'],
    'public origin routes path style only' => [['use_path_style_endpoint' => false, 'presign_use_path_style_endpoint' => true], 'https://media.example.com/media/a.txt?'],
    'public origin routes virtual hosts only' => [['use_path_style_endpoint' => true, 'presign_use_path_style_endpoint' => 'off'], 'https://media.media.example.com/a.txt?'],
]);

it('refuses temporary_url next to a presign endpoint, naming the key', function (): void {
    expect(fn () => presignedDisk(['temporary_url' => 'https://cdn.example.com']))
        ->toThrow(InvalidConfigurationException::class, 'filesystems.disks.media-s3.temporary_url');
});

it('keeps the stock temporary_url re-hosting without a presign endpoint', function (): void {
    $disk = presignedDisk(['presign_endpoint' => null, 'temporary_url' => 'https://proxy.example.com']);

    expect($disk->temporaryUrl('a.txt', presignExpiry()))->toStartWith('https://proxy.example.com/media/a.txt?');
});

it('refuses a public url that would name the in-cluster endpoint', function (): void {
    expect(fn () => presignedDisk()->url('a.txt'))->toThrow(DiskHasNoPublicUrl::class, 'media-s3');
});

it('builds a public url from an explicit disk url', function (): void {
    expect(presignedDisk(['url' => 'https://cdn.example.com'])->url('a.txt'))->toBe('https://cdn.example.com/a.txt')
        ->and(presignedDisk(['presign_endpoint' => null])->url('a.txt'))->toBe('http://rustfs:9000/media/a.txt');
});

it('refuses an unusable presign endpoint, naming the key and never the value', function (mixed $endpoint): void {
    try {
        presignedDisk(['presign_endpoint' => $endpoint]);
        $this->fail('The disk was built.');
    } catch (InvalidConfigurationException $exception) {
        expect($exception->getMessage())->toContain('filesystems.disks.media-s3.presign_endpoint')
            ->and($exception->getMessage())->not->toContain('s3cr3t')
            ->and($exception->getMessage())->not->toContain('cdn.example.com');
    }
})->with([
    'relative' => ['/media'],
    'host only' => ['cdn.example.com'],
    'not http' => ['ftp://cdn.example.com'],
    'a path' => ['https://cdn.example.com/media'],
    'a query' => ['https://cdn.example.com/?a=1'],
    'an empty query' => ['https://cdn.example.com/?'],
    'a fragment' => ['https://cdn.example.com/#top'],
    'userinfo' => ['https://key:s3cr3t@cdn.example.com'],
    'whitespace' => ['https://cdn.example.com /'],
    'no host' => ['https://:443'],
    'not a string' => [['https://cdn.example.com']],
]);

it('reads a blank presign endpoint as off', function (): void {
    expect(presignedDisk(['presign_endpoint' => '  ']))->not->toBeInstanceOf(PresigningS3Adapter::class);
});

it('refuses an unreadable path-style switch, naming the key', function (): void {
    expect(fn () => presignedDisk(['presign_use_path_style_endpoint' => 'sometimes']))
        ->toThrow(InvalidConfigurationException::class, 'filesystems.disks.media-s3.presign_use_path_style_endpoint');
});

it('surfaces the sdk refusal of an expiry beyond seven days', function (): void {
    expect(fn () => presignedDisk()->temporaryUrl('a.txt', new DateTimeImmutable('+8 days')))
        ->toThrow(InvalidArgumentException::class, 'less than one week');
});

it('explains a missing s3 stack instead of failing on a missing class', function (string $missing): void {
    $config = ['driver' => 'presigned-s3', 'bucket' => 'media', 'region' => 'eu-central-1'];
    config()->set('filesystems.disks.uploads', $config);

    $driver = new PresignedS3Driver(app('filesystem'), static fn (string $class): bool => $class !== $missing);

    expect(fn () => $driver->create($config))
        ->toThrow(InvalidConfigurationException::class, 'filesystems.disks.uploads.driver');
})->with(['Aws\S3\S3Client', 'League\Flysystem\AwsS3V3\AwsS3V3Adapter']);

it('names an on-demand disk it cannot find in the config', function (): void {
    expect(fn () => Storage::build(['driver' => 'presigned-s3', 'bucket' => 'media', 'region' => 'eu-central-1', 'presign_endpoint' => 'nope']))
        ->toThrow(InvalidConfigurationException::class, 'filesystems.disks.ondemand.presign_endpoint');
});

it('returns public-origin urls through the media library', function (): void {
    presignedDisk();
    $user = TestUser::query()->create(['name' => 'Ada']);
    Media::factory()->private()->create([
        'model_type' => $user->getMorphClass(),
        'model_id' => (string) $user->getKey(),
        'bucket_name' => 'gallery',
        'disk' => 'media-s3',
    ]);

    $url = MediaLibrary::for($user)->temporaryUrl('gallery');

    expect($url)->toStartWith('https://media.example.com/media/')
        ->and(presignQuery($url))->toHaveKey('X-Amz-Signature')
        ->and($url)->not->toContain('rustfs');
});

it('refuses a framework s3 factory that stops returning the s3 adapter', function (): void {
    $filesystem = new class(app()) extends FilesystemManager
    {
        public function createS3Driver(array $config)
        {
            return $this->createLocalDriver(['root' => sys_get_temp_dir()]);
        }
    };

    expect(fn () => (new PresignedS3Driver($filesystem))->create(['driver' => 'presigned-s3']))
        ->toThrow(LogicException::class, 'not an '.AwsS3V3Adapter::class);
});
