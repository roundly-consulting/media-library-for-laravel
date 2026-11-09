<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Actions;

use InvalidArgumentException;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\UploadPart;
use RoundlyConsulting\MediaLibrary\Enums\DirectUploadMethod;
use RoundlyConsulting\MediaLibrary\Enums\DirectUploadStatus;
use RoundlyConsulting\MediaLibrary\Exceptions\DirectUploadNotFound;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\MediaLibrary\Filesystem\S3UploadSigner;
use RoundlyConsulting\MediaLibrary\Filesystem\StoredPart;
use RoundlyConsulting\MediaLibrary\Models\MediaUpload;
use RoundlyConsulting\MediaLibrary\Uploads\StoredObjects;
use RoundlyConsulting\MediaLibrary\Uploads\UploadTickets;
use SensitiveParameter;

/**
 * Signs parts of a multipart direct upload, in batches: `MediaLibrary::signUploadParts($token, [1, 2])`.
 *
 * Before each batch the parts already uploaded are listed: a part bigger than the ticket's part
 * size, or parts adding up past its cap, reject the ticket and abort the upload — so the object
 * can never exceed the cap, though no single signature limits a part's size.
 */
final class SignUploadPartsAction
{
    public function __construct(
        private readonly UploadTickets $tickets,
        private readonly StoredObjects $objects,
        private readonly S3UploadSigner $s3,
    ) {}

    /**
     * @param  list<int>  $parts
     * @return list<UploadPart>
     *
     * @throws InvalidArgumentException for a part number outside the ticket's parts
     */
    public function execute(#[SensitiveParameter] string $token, array $parts): array
    {
        $upload = $this->tickets->find($token);
        $this->tickets->ensurePending($upload);

        $disk = $this->objects->s3($upload->disk);

        if ($upload->method() !== DirectUploadMethod::Multipart || $disk === null || $upload->multipart_upload_id === null) {
            throw DirectUploadNotFound::forToken();
        }

        foreach ($parts as $number) {
            if ($number < 1 || $number > (int) $upload->part_count) {
                throw new InvalidArgumentException("Part {$number} is outside 1-{$upload->part_count}.");
            }
        }

        $this->ensureWithinCap($upload, $this->s3->listParts($disk, $upload->path, $upload->multipart_upload_id), $this->tickets);

        $signed = [];

        foreach (array_values(array_unique($parts)) as $number) {
            ['url' => $url, 'headers' => $headers] = $this->s3->presignPart($disk, $upload->path, $upload->multipart_upload_id, $number, $upload->expires_at);
            $signed[] = new UploadPart($number, $url, $headers);
        }

        return $signed;
    }

    /**
     * Reject the ticket when its uploaded parts break its part size or cap.
     *
     * @param  array<int, StoredPart>  $uploaded
     *
     * @throws FileUnacceptableForBucket
     */
    public static function ensureWithinCap(MediaUpload $upload, array $uploaded, UploadTickets $tickets): void
    {
        $total = 0;
        $oversized = false;

        foreach ($uploaded as $part) {
            $total += $part->size;
            $oversized = $oversized || $part->size > (int) $upload->part_size;
        }

        if (! $oversized && $total <= $upload->max_size) {
            return;
        }

        $refusal = FileUnacceptableForBucket::tooLarge($total, $upload->max_size, $upload->bucket_name);

        if ($upload->status === DirectUploadStatus::Pending) {
            $tickets->transition($upload, DirectUploadStatus::Pending, DirectUploadStatus::Adopting);
        }

        $tickets->reject($upload, $refusal->getMessage());

        throw $refusal;
    }
}
