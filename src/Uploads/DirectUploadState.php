<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Uploads;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\MediaLibrary\Actions\IssueDirectUploadAction;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;

/**
 * Immutable snapshot of a {@see PendingDirectUpload} handed to {@see IssueDirectUploadAction}.
 */
final readonly class DirectUploadState
{
    /**
     * @param  array<string, mixed>  $customProperties
     */
    public function __construct(
        public HasMedia|Model|null $owner,
        public string $bucket,
        public string $fileName,
        public int $size,
        public ?string $declaredType = null,
        public ?string $sha256 = null,
        public bool $multipart = false,
        public ?int $partSize = null,
        public ?Model $actor = null,
        public ?string $name = null,
        public array $customProperties = [],
        public ?string $queue = null,
        public ?int $expiresIn = null,
        public ?string $visibility = null,
        public ?string $diskOverride = null,
        public bool $draft = false,
    ) {}
}
