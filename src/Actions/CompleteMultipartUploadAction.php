<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\MediaLibrary\Enums\DirectUploadMethod;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadIncomplete;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadNotFound;
use RoundlyConsulting\MediaLibrary\Filesystem\S3UploadSigner;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Models\MediaUpload;
use RoundlyConsulting\MediaLibrary\Uploads\StoredObjects;
use RoundlyConsulting\MediaLibrary\Uploads\UploadTickets;
use SensitiveParameter;

/**
 * Finishes a multipart direct upload and adopts it: `MediaLibrary::completeUpload($token, [1 => $etag])`.
 *
 * Under the adoption claim — so an abort or prune cannot interleave — the uploaded parts are
 * listed and checked against the ETags the browser reports and against the ticket's part size
 * and cap; only then does the server complete the upload (after which no part URL can change the
 * object) and adopt it like any direct upload.
 */
final class CompleteMultipartUploadAction
{
    public function __construct(
        private readonly AdoptDirectUploadAction $adopt,
        private readonly UploadTickets $tickets,
        private readonly StoredObjects $objects,
        private readonly S3UploadSigner $s3,
    ) {}

    /**
     * @param  array<int, string>  $parts  part number => ETag the browser received
     */
    public function execute(#[SensitiveParameter] string $token, array $parts, ?Model $actor = null): Media
    {
        return $this->adopt->execute($token, $actor, function (MediaUpload $upload) use ($parts): void {
            if ($upload->option('completed', false)) {
                return;
            }

            $disk = $this->objects->s3($upload->disk);

            if ($upload->method() !== DirectUploadMethod::Multipart || $disk === null || $upload->multipart_upload_id === null) {
                throw DirectUploadNotFound::forToken();
            }

            $uploaded = $this->s3->listParts($disk, $upload->path, $upload->multipart_upload_id);
            SignUploadPartsAction::ensureWithinCap($upload, $uploaded, $this->tickets);

            $complete = [];
            ksort($parts);

            foreach ($parts as $number => $etag) {
                $part = $uploaded[$number] ?? null;

                if ($part === null || $part->etag !== trim($etag, '"')) {
                    throw DirectUploadIncomplete::partsMissing();
                }

                $complete[] = $part;
            }

            if ($complete === []) {
                throw DirectUploadIncomplete::partsMissing();
            }

            $this->s3->completeMultipart($disk, $upload->path, $upload->multipart_upload_id, $complete);

            $upload->forceFill(['options' => array_merge($upload->options ?? [], ['completed' => true])])->save();
        });
    }
}
