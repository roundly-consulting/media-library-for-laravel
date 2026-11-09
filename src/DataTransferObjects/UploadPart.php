<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\DataTransferObjects;

/** One signed part of a multipart direct upload: `PUT` its bytes to `url`. */
final readonly class UploadPart
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public int $partNumber,
        public string $url,
        public array $headers = [],
    ) {}
}
