<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Buckets;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\AddedFile;
use RoundlyConsulting\MediaLibrary\Exceptions\FileDoesNotExist;
use RoundlyConsulting\MediaLibrary\Exceptions\InvalidBase64Data;

/**
 * Normalizes every supported media source (upload, path, URL, disk file, raw string,
 * base64, stream) into an {@see AddedFile} materialized on the local filesystem, and builds
 * the {@see PendingFileAdd} the caller chains on.
 */
final class FileAdderFactory
{
    public function fromFile(HasMedia|Model|null $owner, string|UploadedFile $file): PendingFileAdd
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
                $owner,
                $contents === false ? '' : $contents,
                $file->getClientOriginalName(),
                $file->getMimeType(),
            );
        }

        return $this->fromPath($owner, $file);
    }

    public function fromPath(HasMedia|Model|null $owner, string $path): PendingFileAdd
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw FileDoesNotExist::forPath($path);
        }

        $fileName = basename($path);
        $size = filesize($path);

        return $this->build($owner, new AddedFile(
            path: $path,
            name: $this->displayName($fileName),
            fileName: $this->sanitizeFileName($fileName),
            mimeType: $this->detectMimeType($path),
            extension: pathinfo($fileName, PATHINFO_EXTENSION) ?: null,
            size: $size === false ? 0 : $size,
        ));
    }

    public function fromUrl(HasMedia|Model|null $owner, string $url): PendingFileAdd
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
            $owner,
            $response->body(),
            $fileName,
            $response->header('Content-Type') ?: null,
        );
    }

    public function fromDisk(HasMedia|Model|null $owner, string $path, ?string $disk = null): PendingFileAdd
    {
        $disk ??= $this->configDisk();
        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            throw FileDoesNotExist::onDisk($path, $disk);
        }

        $contents = $storage->get($path);

        return $this->fromTempContents(
            $owner,
            $contents ?? '',
            basename($path),
            $storage->mimeType($path) ?: null,
        );
    }

    public function fromString(HasMedia|Model|null $owner, string $contents): PendingFileAdd
    {
        return $this->fromTempContents($owner, $contents, Str::random(40), null);
    }

    public function fromBase64(HasMedia|Model|null $owner, string $base64): PendingFileAdd
    {
        if (str_contains($base64, ',')) {
            $base64 = (string) Str::after($base64, ',');
        }

        $decoded = base64_decode(str_replace(' ', '+', $base64), true);

        if ($decoded === false) {
            throw InvalidBase64Data::make();
        }

        return $this->fromTempContents($owner, $decoded, Str::random(40), null);
    }

    /**
     * @param  resource  $stream
     */
    public function fromStream(HasMedia|Model|null $owner, $stream): PendingFileAdd
    {
        $contents = stream_get_contents($stream);

        if ($contents === false) {
            throw FileDoesNotExist::forPath('stream');
        }

        return $this->fromTempContents($owner, $contents, Str::random(40), null);
    }

    private function fromTempContents(HasMedia|Model|null $owner, string $contents, string $fileName, ?string $mimeType): PendingFileAdd
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

        return $this->build($owner, new AddedFile(
            path: $tempPath,
            name: $this->displayName($fileName),
            fileName: $this->sanitizeFileName($fileName),
            mimeType: $mimeType,
            extension: $extension,
            size: strlen($contents),
            isTemporary: true,
        ));
    }

    private function build(HasMedia|Model|null $owner, AddedFile $file): PendingFileAdd
    {
        return new PendingFileAdd($owner, $file);
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
