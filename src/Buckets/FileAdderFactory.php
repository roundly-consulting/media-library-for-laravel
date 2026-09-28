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
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFileRejected;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;
use RoundlyConsulting\MediaLibrary\Support\FileNames;

/**
 * Normalizes every supported media source (upload, path, URL, disk file, raw string,
 * base64, stream) into an {@see AddedFile} materialized on the local filesystem. The manager
 * wraps the result in the {@see PendingFileAdd} the caller chains on.
 *
 * The mime type is always SNIFFED from the bytes — never taken from a client, a remote
 * `Content-Type` or a disk's stored metadata, all of which an uploader controls — and the stored
 * name is made safe for it by {@see FileNames}.
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

            return $this->fromTempContents($contents === false ? '' : $contents, $file->getClientOriginalName());
        }

        return $this->fromPath($file);
    }

    /** A local path is read in place and never moved or deleted — the add stores a copy. */
    public function fromPath(string $path): AddedFile
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw FileDoesNotExist::forPath($path);
        }

        $size = filesize($path);

        return $this->describe($path, basename($path), $size === false ? 0 : $size, isTemporary: false);
    }

    public function fromUrl(string $url): AddedFile
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if ($scheme !== 'http' && $scheme !== 'https') {
            throw RemoteFileRejected::unsupportedScheme($url);
        }

        $headers = config('media.remote.headers');
        $headers = is_array($headers) ? $headers : [];
        $timeout = config('media.remote.timeout');
        $timeout = is_numeric($timeout) ? (int) $timeout : 30;

        $response = Http::withHeaders($headers)->timeout($timeout)->get($url);

        if (! $response->successful()) {
            throw FileDoesNotExist::forPath($url);
        }

        $limit = $this->maxFileSize();
        $declared = $response->header('Content-Length');

        if ($limit !== null && is_numeric($declared) && (int) $declared > $limit) {
            throw RemoteFileRejected::tooLarge($url, $limit);
        }

        $body = $response->body();

        if ($limit !== null && strlen($body) > $limit) {
            throw RemoteFileRejected::tooLarge($url, $limit);
        }

        $fileName = basename((string) parse_url($url, PHP_URL_PATH)) ?: Str::random(40);

        return $this->fromTempContents($body, $fileName);
    }

    public function fromDisk(string $path, ?string $disk = null): AddedFile
    {
        $disk ??= $this->configDisk();
        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            throw FileDoesNotExist::onDisk($path, $disk);
        }

        return $this->fromTempContents($storage->get($path) ?? '', basename($path));
    }

    public function fromString(string $contents): AddedFile
    {
        return $this->fromTempContents($contents, Str::random(40));
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

        return $this->fromTempContents($decoded, Str::random(40));
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

        return $this->fromTempContents($contents, Str::random(40));
    }

    private function fromTempContents(string $contents, string $fileName): AddedFile
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'media_');

        if ($tempPath === false) {
            throw FileDoesNotExist::forPath('temporary file');
        }

        file_put_contents($tempPath, $contents);

        return $this->describe($tempPath, $fileName, strlen($contents), isTemporary: true);
    }

    /** Sniff the bytes at `$path` and derive a safe stored name from the untrusted `$fileName`. */
    private function describe(string $path, string $fileName, int $size, bool $isTemporary): AddedFile
    {
        $mimeType = $this->detectMimeType($path);
        $safeName = FileNames::conform(FileNames::sanitize($fileName), $mimeType);

        return new AddedFile(
            path: $path,
            name: $this->displayName($fileName),
            fileName: $safeName,
            mimeType: $mimeType,
            extension: FileNames::extensionOf($safeName),
            size: $size,
            isTemporary: $isTemporary,
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

    /** The human display name: the untrusted name's last segment, without its extension. */
    private function displayName(string $fileName): string
    {
        $fileName = basename(str_replace('\\', '/', $fileName));

        return pathinfo($fileName, PATHINFO_FILENAME) ?: $fileName;
    }

    /** The package-level size cap (`media.max_file_size`), or null when there is none. */
    private function maxFileSize(): ?int
    {
        return BucketValidationRules::maxFileSizeFor(null);
    }

    private function configDisk(): string
    {
        $disk = config('media.disk');

        return is_string($disk) ? $disk : 'public';
    }
}
