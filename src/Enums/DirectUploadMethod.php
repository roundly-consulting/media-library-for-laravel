<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Enums;

/** How a direct upload's bytes reach storage. */
enum DirectUploadMethod: string
{
    /** One presigned `PUT` of the whole file to `url` with `headers`. */
    case Put = 'put';

    /** A browser form `POST` to `url` with `fields` (S3 POST policy; the size cap is signed). */
    case Post = 'post';

    /** S3 multipart: sign parts with `signUploadParts()`, then `completeUpload()`. */
    case Multipart = 'multipart';
}
