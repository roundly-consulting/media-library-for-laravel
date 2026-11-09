<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\DataTransferObjects;

use Carbon\CarbonImmutable;
use RoundlyConsulting\MediaLibrary\Enums\DirectUploadMethod;
use SensitiveParameter;

/**
 * An issued direct upload — hand `method`, `url`, `headers` / `fields` to the browser, keep
 * `token` to adopt the file afterwards. The token is shown only here; the package stores a hash.
 */
final readonly class DirectUpload
{
    /**
     * @param  array<string, string>  $headers  send with a `put` (or each multipart part)
     * @param  array<string, string>  $fields  the form fields of a `post`, before the file field
     */
    public function __construct(
        #[SensitiveParameter] public string $token,
        public DirectUploadMethod $method,
        public string $url,
        public array $headers,
        public array $fields,
        public string $key,
        public int $maxSize,
        public bool $sizeEnforcedBySignature,
        public CarbonImmutable $expiresAt,
        public string $mediaUuid,
        public ?int $partSize = null,
        public ?int $partCount = null,
    ) {}
}
