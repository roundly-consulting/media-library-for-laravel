<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Tests\Fixtures;

use Aws\Api\DateTimeResult;
use Aws\Command;
use Aws\CommandInterface;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;

/**
 * An in-memory S3 for the SDK's `handler` option: every command a direct upload sends is answered
 * from `$objects` (key => bytes) and logged, so a test can prove which requests were made — a
 * ranged read, a server-side copy — and which never were (a PUT of the bytes through the app).
 */
final class FakeS3
{
    /** @var array<string, string> */
    public array $objects = [];

    /** @var array<string, array<string, string>> metadata per key (ContentType, ContentDisposition, ACL, ChecksumSHA256) */
    public array $metadata = [];

    /** @var array<string, array{key: string, parts: array<int, string>, initiated: int}> */
    public array $multipart = [];

    /** @var list<array{name: string, params: array<string, mixed>}> */
    public array $log = [];

    /** @return list<string> */
    public function names(): array
    {
        return array_column($this->log, 'name');
    }

    /** @return list<array<string, mixed>> */
    public function params(string $name): array
    {
        return array_values(array_map(
            static fn (array $entry): array => $entry['params'],
            array_filter($this->log, static fn (array $entry): bool => $entry['name'] === $name),
        ));
    }

    public function __invoke(CommandInterface $command, RequestInterface $request): PromiseInterface
    {
        $name = $command->getName();
        $params = $command->toArray();
        $this->log[] = ['name' => $name, 'params' => $params];
        $key = (string) ($params['Key'] ?? '');

        try {
            return Create::promiseFor(new Result($this->answer($name, $key, $params)));
        } catch (S3Exception $exception) {
            return Create::rejectionFor($exception);
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function answer(string $name, string $key, array $params): array
    {
        return match ($name) {
            'HeadObject' => $this->head($key),
            'GetObject' => $this->get($key, (string) ($params['Range'] ?? '')),
            'CopyObject' => $this->copy($key, $params),
            'DeleteObject' => $this->delete($key),
            'PutObject' => $this->put($key, $params),
            'CreateMultipartUpload' => $this->create($key),
            'ListParts' => $this->listParts((string) $params['UploadId']),
            'CompleteMultipartUpload' => $this->complete((string) $params['UploadId']),
            'AbortMultipartUpload' => $this->abort((string) $params['UploadId']),
            'ListMultipartUploads' => $this->listUploads(),
            'ListObjectsV2' => $this->listObjects((string) ($params['Prefix'] ?? '')),
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function head(string $key): array
    {
        if (! array_key_exists($key, $this->objects)) {
            throw new S3Exception('Not Found', $this->commandFor('HeadObject'), ['code' => 'NotFound', 'response' => new Response(404)]);
        }

        return ['ContentLength' => strlen($this->objects[$key]), 'LastModified' => new DateTimeResult('@'.(time() - 86400))] + ($this->metadata[$key] ?? []);
    }

    /** @return array<string, mixed> */
    private function get(string $key, string $range): array
    {
        if (! array_key_exists($key, $this->objects)) {
            throw new S3Exception('Not Found', $this->commandFor('GetObject'), ['code' => 'NoSuchKey', 'response' => new Response(404)]);
        }

        $bytes = $this->objects[$key];

        if (preg_match('/^bytes=(\d+)-(\d+)$/', $range, $match) === 1) {
            $bytes = substr($bytes, (int) $match[1], (int) $match[2] - (int) $match[1] + 1);
        }

        return ['Body' => Utils::streamFor($bytes), 'ContentLength' => strlen($bytes)];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function copy(string $key, array $params): array
    {
        [, $source] = explode('/', rawurldecode((string) $params['CopySource']), 2);
        $this->objects[$key] = $this->objects[$source] ?? throw new S3Exception('Not Found', $this->commandFor('CopyObject'), ['code' => 'NoSuchKey', 'response' => new Response(404)]);
        $this->metadata[$key] = array_filter([
            'ContentType' => $params['ContentType'] ?? null,
            'ContentDisposition' => $params['ContentDisposition'] ?? null,
            'ACL' => $params['ACL'] ?? null,
        ]);

        return [];
    }

    /** @return array<string, mixed> */
    private function delete(string $key): array
    {
        unset($this->objects[$key], $this->metadata[$key]);

        return [];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function put(string $key, array $params): array
    {
        $this->objects[$key] = (string) ($params['Body'] ?? '');

        return [];
    }

    /** @return array<string, mixed> */
    private function create(string $key): array
    {
        $id = 'upload-'.count($this->multipart);
        $this->multipart[$id] = ['key' => $key, 'parts' => [], 'initiated' => time() - 86400];

        return ['UploadId' => $id];
    }

    /** Simulate the browser's PUT of one part. */
    public function uploadPart(string $uploadId, int $number, string $bytes): string
    {
        $this->multipart[$uploadId]['parts'][$number] = $bytes;

        return md5($bytes);
    }

    /** @return array<string, mixed> */
    private function listParts(string $uploadId): array
    {
        $parts = [];

        foreach ($this->multipart[$uploadId]['parts'] ?? [] as $number => $bytes) {
            $parts[] = ['PartNumber' => $number, 'Size' => strlen($bytes), 'ETag' => '"'.md5($bytes).'"'];
        }

        return ['Parts' => $parts, 'IsTruncated' => false];
    }

    /** @return array<string, mixed> */
    private function complete(string $uploadId): array
    {
        $upload = $this->multipart[$uploadId];
        ksort($upload['parts']);
        $this->objects[$upload['key']] = implode('', $upload['parts']);
        unset($this->multipart[$uploadId]);

        return [];
    }

    /** @return array<string, mixed> */
    private function abort(string $uploadId): array
    {
        unset($this->multipart[$uploadId]);

        return [];
    }

    /** @return array<string, mixed> */
    private function listUploads(): array
    {
        $uploads = [];

        foreach ($this->multipart as $id => $upload) {
            $uploads[] = ['Key' => $upload['key'], 'UploadId' => $id, 'Initiated' => new DateTimeResult('@'.$upload['initiated'])];
        }

        return ['Uploads' => $uploads, 'IsTruncated' => false];
    }

    /** @return array<string, mixed> */
    private function listObjects(string $prefix): array
    {
        $contents = [];

        foreach ($this->objects as $key => $bytes) {
            if (str_starts_with($key, $prefix)) {
                $contents[] = ['Key' => $key, 'Size' => strlen($bytes), 'LastModified' => new DateTimeResult('@'.(time() - 86400))];
            }
        }

        return ['Contents' => $contents, 'IsTruncated' => false, 'KeyCount' => count($contents)];
    }

    private function commandFor(string $name): CommandInterface
    {
        return new Command($name);
    }
}
