<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\DataTransferObjects;

use InvalidArgumentException;
use RoundlyConsulting\MediaLibrary\Enums\ContentDisposition;

/**
 * The response a temporary URL asks for — `Media::getTemporaryUrlWithOptions()`.
 *
 * On S3 and S3-compatible disks they become the presigned URL's `response-content-disposition`
 * and `response-content-type`, so the storage origin answers with them. Every null takes the
 * streaming route's rule: the file's own type, and `inline` only for a type a browser renders
 * without running anything (raster images, audio, video, PDF, plain text) — `attachment` for
 * everything else. `inline` is never honoured for any other type.
 */
final readonly class TemporaryUrlOptions
{
    /**
     * @throws InvalidArgumentException for a content type that is not a `type/subtype` media
     *                                  type, or a file name that is blank or holds a control
     *                                  character
     */
    public function __construct(
        public ?ContentDisposition $disposition = null,
        public ?string $fileName = null,
        public ?string $contentType = null,
    ) {
        if ($contentType !== null && preg_match('#^[A-Za-z0-9!\#$&^_.+-]+/[A-Za-z0-9!\#$&^_.+-]+(\s*;\s*[A-Za-z0-9!\#$&^_.+-]+=("[^"\x00-\x1F\x7F]*"|[A-Za-z0-9!\#$&^_.+-]+))*$#D', $contentType) !== 1) {
            throw new InvalidArgumentException('contentType must be a media type such as [application/pdf].');
        }

        if ($fileName !== null && (trim($fileName) === '' || preg_match('/[\x00-\x1F\x7F]/', $fileName) === 1)) {
            throw new InvalidArgumentException('fileName must be non-blank and free of control characters.');
        }
    }

    /** Ask for an attachment (a download), optionally under another file name. */
    public static function download(?string $fileName = null): self
    {
        return new self(ContentDisposition::Attachment, $fileName);
    }
}
