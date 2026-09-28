<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Buckets;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\AddedFile;
use RoundlyConsulting\MediaLibrary\Exceptions\FileDoesNotExist;
use RoundlyConsulting\MediaLibrary\Exceptions\InvalidBase64Data;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;

/**
 * Normalizes every supported media source (upload, path, URL, disk file, raw string,
 * base64, stream) into an {@see AddedFile} materialized on the local filesystem. The manager
 * wraps the result in the {@see PendingFileAdd} the caller chains on.
 *
 * @internal building block of {@see MediaLibraryManager}
 */
final class FileAdderFactory
{
    public function fromFile(string|UploadedFile $file): AddedFile
    {
        if ($file instanceof UploadedFile) {
            $path = $file->getRealPath();

            if ($path === false || ! is_file($path)) {
                throw FileDoesNotExist::forPath($file->getClientOriginalName());
            }

            // Copy the upload's bytes into our own temp file immediately: the UploadedFile
            // (especially fakes) may be cleaned up before the deferred terminal add runs.
            $contents = file_get_contents($path);

            return $this->fromTempContents(
                $contents === false ? '' : $contents,
                $file->getClientOriginalName(),
                $file->getMimeType(),
            );
        }

        return $this->fromPath($file);
    }

    public function fromPath(string $path): AddedFile
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw FileDoesNotExist::forPath($path);
        }

        $fileName = basename($path);
        $size = filesize($path);

        return new AddedFile(
            path: $path,
            name: $this->displayName($fileName),
            fileName: $this->sanitizeFileName($fileName),
            mimeType: $this->detectMimeType($path),
            extension: pathinfo($fileName, PATHINFO_EXTENSION) ?: null,
            size: $size === false ? 0 : $size,
        );
    }

    public function fromUrl(string $url): AddedFile
    {
        $headers = config('media.remote.headers');
        $headers = is_array($headers) ? $headers : [];
        $timeout = config('media.remote.timeout');
        $timeout = is_int($timeout) ? $timeout : 30;

        $response = Http::withHeaders($headers)->timeout($timeout)->get($url);

        if (! $response->successful()) {
            throw FileDoesNotExist::forPath($url);
        }

        $fileName = basename((string) parse_url($url, PHP_URL_PATH)) ?: Str::random(40);

        return $this->fromTempContents(
            $response->body(),
            $fileName,
            $response->header('Content-Type') ?: null,
        );
    }

    public function fromDisk(string $path, ?string $disk = null): AddedFile
    {
        $disk ??= $this->configDisk();
        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            throw FileDoesNotExist::onDisk($path, $disk);
        }

        $contents = $storage->get($path);

        return $this->fromTempContents(
            $contents ?? '',
            basename($path),
            $storage->mimeType($path) ?: null,
        );
    }

    public function fromString(string $contents): AddedFile
    {
        return $this->fromTempContents($contents, Str::random(40), null);
    }

    public function fromBase64(string $base64): AddedFile
    {
        if (str_contains($base64, ',')) {
            $base64 = (string) Str::after($base64, ',');
        }

        $decoded = base64_decode(str_replace(' ', '+', $base64), true);

        if ($decoded === false) {
            throw InvalidBase64Data::make();
        }

        return $this->fromTempContents($decoded, Str::random(40), null);
    }

    /**
     * @param  resource  $stream
     */
    public function fromStream($stream): AddedFile
    {
        $contents = stream_get_contents($stream);

        if ($contents === false) {
            throw FileDoesNotExist::forPath('stream');
        }

        return $this->fromTempContents($contents, Str::random(40), null);
    }

    private function fromTempContents(string $contents, string $fileName, ?string $mimeType): AddedFile
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'media_');

        if ($tempPath === false) {
            throw FileDoesNotExist::forPath('temporary file');
        }

        file_put_contents($tempPath, $contents);

        $mimeType ??= $this->detectMimeType($tempPath);
        $extension = pathinfo($fileName, PATHINFO_EXTENSION) ?: $this->extensionFromMime($mimeType);

        if ($extension !== null && pathinfo($fileName, PATHINFO_EXTENSION) === '') {
            $fileName .= '.'.$extension;
        }

        return new AddedFile(
            path: $tempPath,
            name: $this->displayName($fileName),
            fileName: $this->sanitizeFileName($fileName),
            mimeType: $mimeType,
            extension: $extension,
            size: strlen($contents),
            isTemporary: true,
        );
    }

    private function detectMimeType(string $path): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            return null;
        }

        $mime = finfo_file($finfo, $path);
        finfo_close($finfo);

        return $mime === false ? null : $mime;
    }

    private function extensionFromMime(?string $mimeType): ?string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
            'text/plain' => 'txt',
            default => null,
        };
    }

    private function displayName(string $fileName): string
    {
        return pathinfo($fileName, PATHINFO_FILENAME) ?: $fileName;
    }

    private function sanitizeFileName(string $fileName): string
    {
        $fileName = str_replace(['#', '/', '\\', ' '], '-', $fileName);

        return ltrim($fileName, '.') ?: 'file';
    }

    private function configDisk(): string
    {
        $disk = config('media.disk');

        return is_string($disk) ? $disk : 'public';
    }
}
