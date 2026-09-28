<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Buckets;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\MediaLibrary\Actions\AddMediaAction;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\AddedFile;

/**
 * Immutable snapshot of a {@see PendingFileAdd} handed to {@see AddMediaAction}.
 */
final readonly class PendingFileAddState
{
    /**
     * @param  array<string, mixed>  $customProperties
     */
    public function __construct(
        public HasMedia|Model|null $owner,
        public AddedFile $file,
        public string $bucket,
        public ?string $name,
        public ?string $fileName,
        public ?string $diskOverride,
        public ?string $variantsDiskOverride,
        public ?string $visibility,
        public array $customProperties,
        public ?string $queue = null,
        public bool $draft = false,
    ) {}
}
