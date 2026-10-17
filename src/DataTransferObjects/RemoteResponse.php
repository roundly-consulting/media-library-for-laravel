<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\DataTransferObjects;

/**
 * What `MediaLibrary::remote()->fetch()` brought back: always a 2xx answer, its body at most the
 * byte cap, from `url` — the URL the last redirect led to.
 */
final readonly class RemoteResponse
{
    /**
     * @param  array<string, list<string>>  $headers  as the server sent them, by name
     */
    public function __construct(
        public string $body,
        public int $status,
        public ?string $contentType,
        public string $url,
        public array $headers,
    ) {}
}
