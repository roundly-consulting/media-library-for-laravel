<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Uploads;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadsNotSupported;
use RoundlyConsulting\MediaLibrary\Exceptions\FileCannotBeWritten;
use RoundlyConsulting\MediaLibrary\Filesystem\S3UploadSigner;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\RollbackCallbacks;
use Throwable;

/**
 * Reads and moves files that are already in storage — what adopting a direct upload needs — on
 * any disk: S3 disks through {@see S3UploadSigner} (ranged reads, server-side copies), every other
 * disk through Laravel's filesystem (`readStream`, `move`). Nothing here downloads a whole file
 * into the application except a streamed hash.
 *
 * @internal
 */
final class StoredObjects
{
    public function __construct(
        private readonly S3UploadSigner $s3,
        private readonly RollbackCallbacks $rollbacks,
    ) {}

    public function s3(string $disk): ?AwsS3V3Adapter
    {
        $storage = Storage::disk($disk);

        return $storage instanceof AwsS3V3Adapter ? $storage : null;
    }

    /** The object's size in bytes, or null when it is not there. */
    public function size(string $disk, string $path): ?int
    {
        try {
            return Storage::disk($disk)->exists($path) ? Storage::disk($disk)->size($path) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** The object's first `$length` bytes (one ranged read on S3). */
    public function head(string $disk, string $path, int $length): string
    {
        $s3 = $this->s3($disk);

        if ($s3 !== null) {
            return $this->s3->readHead($s3, $path, $length);
        }

        $stream = Storage::disk($disk)->readStream($path);

        if (! is_resource($stream)) {
            return '';
        }

        $head = '';

        while (($missing = $length - strlen($head)) > 0 && ! feof($stream)) {
            $chunk = fread($stream, $missing);

            if ($chunk === false || $chunk === '') {
                break;
            }

            $head .= $chunk;
        }

        fclose($stream);

        return $head;
    }

    /** The sha256 (hex) the store itself recorded for the object, or null. */
    public function storedSha256(string $disk, string $path): ?string
    {
        $s3 = $this->s3($disk);

        return $s3 === null ? null : $this->s3->storedSha256($s3, $path);
    }

    /**
     * Move an object inside storage: a server-side copy with fresh metadata on S3 (across two S3
     * disks too), a filesystem move on any other disk. It never passes through the application.
     *
     * @throws DirectUploadsNotSupported when the two disks cannot move between each other
     * @throws FileCannotBeWritten when the disk refuses
     */
    public function move(string $fromDisk, string $fromPath, string $toDisk, string $toPath, int $size, ?string $mimeType, string $disposition, string $visibility): void
    {
        $from = $this->s3($fromDisk);
        $to = $this->s3($toDisk);

        if ($from !== null && $to !== null) {
            $this->s3->copy($from, $fromPath, $to, $toPath, $size, $mimeType, $disposition, $visibility);
            $from->delete($fromPath);

            return;
        }

        if ($fromDisk !== $toDisk) {
            throw DirectUploadsNotSupported::crossDisk($fromDisk, $toDisk);
        }

        $storage = Storage::disk($toDisk);

        if (! $storage->move($fromPath, $toPath)) {
            throw FileCannotBeWritten::toDisk($toPath, $toDisk);
        }

        $storage->setVisibility($toPath, $visibility);
    }

    /** Whether an object on `$fromDisk` can be moved to `$toDisk` without leaving storage. */
    public function canMove(string $fromDisk, string $toDisk): bool
    {
        return $fromDisk === $toDisk || ($this->s3($fromDisk) !== null && $this->s3($toDisk) !== null);
    }

    /**
     * Should the transaction that saved `$media` roll back, put the object it moved to `$toPath`
     * back where it came from, so its ticket can be adopted again.
     */
    public function moveBackAfterRollBack(Media $media, string $fromDisk, string $fromPath, string $toDisk, string $toPath, int $size, string $visibility): void
    {
        $this->rollbacks->register($media->getConnection(), function () use ($fromDisk, $fromPath, $toDisk, $toPath, $size): void {
            try {
                $this->move($toDisk, $toPath, $fromDisk, $fromPath, $size, null, 'attachment', 'private');
            } catch (Throwable $exception) {
                app(ExceptionHandler::class)->report($exception);
            }
        });
    }

    /** Delete an object; a failure is reported, never thrown (it runs on cleanup paths). */
    public function delete(string $disk, string $path): void
    {
        try {
            Storage::disk($disk)->delete($path);
        } catch (Throwable $exception) {
            app(ExceptionHandler::class)->report($exception);
        }
    }
}
