<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Uploads;

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\AddedFile;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\StoredObject;
use RoundlyConsulting\MediaLibrary\Enums\ChecksumAlgorithm;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadIncomplete;
use RoundlyConsulting\MediaLibrary\Support\ExifOrientation;
use RoundlyConsulting\MediaLibrary\Support\FileNames;
use RoundlyConsulting\MediaLibrary\Support\ImageDecodeGuard;
use RoundlyConsulting\MediaLibrary\Support\MediaConfig;

/**
 * Describes a file already in storage the way an add describes a local one — size, a type
 * sniffed from its leading bytes (never the stored `Content-Type`, which the uploader chose),
 * displayed dimensions, checksum — without writing it anywhere.
 *
 * The leading bytes are one ranged read. The checksum is one streamed pass in constant memory
 * (or the store's own sha256, when the disk is trusted for it); a raster image small enough is
 * kept as a local copy during that pass so placeholders can be computed.
 *
 * @internal
 */
final class InspectStoredObject
{
    /** Enough of the file for a JPEG's late size marker, or a long EXIF block. */
    private const int DIMENSION_BYTES = 524288;

    public function __construct(
        private readonly StoredObjects $objects,
    ) {}

    /**
     * @throws DirectUploadIncomplete when the object is not in storage
     */
    public function size(string $disk, string $path): int
    {
        return $this->objects->size($disk, $path) ?? throw DirectUploadIncomplete::objectMissing();
    }

    /**
     * @throws DirectUploadIncomplete when the object is not in storage
     */
    public function inspect(string $disk, string $path, string $declaredName, bool $inPlace = false, bool $trustStoredChecksum = false): AddedFile
    {
        $size = $this->size($disk, $path);
        $head = $this->objects->head($disk, $path, min($size, max(MediaConfig::sniffBytes(), self::DIMENSION_BYTES)));
        $mimeType = $this->sniff(substr($head, 0, MediaConfig::sniffBytes()));

        $dimensions = str_starts_with((string) $mimeType, 'image/')
            ? ExifOrientation::displayDimensionsFromBytes($head)
            : null;

        $keepLocal = ImageDecodeGuard::decodes($mimeType) && $size <= MediaConfig::probeMaxBytes();
        $checksum = null;
        $probe = null;

        if ($trustStoredChecksum && ! $keepLocal && MediaConfig::checksumAlgorithm() === ChecksumAlgorithm::Sha256) {
            $checksum = $this->objects->storedSha256($disk, $path);
        }

        if ($checksum === null) {
            [$checksum, $probe] = $this->hash($disk, $path, $keepLocal);
        }

        $fileName = FileNames::conform(FileNames::sanitize($declaredName), $mimeType);
        $display = basename(str_replace('\\', '/', $declaredName));

        return new AddedFile(
            path: $probe ?? '',
            name: FileNames::displayName(pathinfo($display, PATHINFO_FILENAME) ?: $display),
            fileName: $fileName,
            mimeType: $mimeType,
            extension: FileNames::extensionOf($fileName),
            size: $size,
            isTemporary: $probe !== null,
            object: new StoredObject($disk, $path, $size, $mimeType, $checksum, $dimensions[0] ?? null, $dimensions[1] ?? null, $inPlace),
        );
    }

    private function sniff(string $bytes): ?string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo === false || $bytes === '' ? false : finfo_buffer($finfo, $bytes);

        return $mime === false ? null : $mime;
    }

    /**
     * One streamed pass: the checksum, and a local copy when `$keepLocal`.
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function hash(string $disk, string $path, bool $keepLocal): array
    {
        $stream = Storage::disk($disk)->readStream($path);

        if (! is_resource($stream)) {
            throw DirectUploadIncomplete::objectMissing();
        }

        $context = hash_init(MediaConfig::checksumAlgorithm()->value);
        $probe = $keepLocal ? tempnam(sys_get_temp_dir(), 'media_') : false;
        $local = $probe === false ? false : fopen($probe, 'wb');

        while (! feof($stream)) {
            $chunk = fread($stream, 1048576);

            if ($chunk === false || $chunk === '') {
                break;
            }

            hash_update($context, $chunk);

            if ($local !== false) {
                fwrite($local, $chunk);
            }
        }

        fclose($stream);

        if ($local !== false) {
            fclose($local);
        }

        return [hash_final($context), $probe === false ? null : $probe];
    }
}
