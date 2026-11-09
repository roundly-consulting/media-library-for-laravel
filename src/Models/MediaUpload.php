<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\MediaLibrary\Database\Factories\MediaUploadFactory;
use RoundlyConsulting\MediaLibrary\Enums\DirectUploadMethod;
use RoundlyConsulting\MediaLibrary\Enums\DirectUploadStatus;
use RoundlyConsulting\MediaLibrary\Support\MediaConfig;

/**
 * A direct-upload ticket: what was signed (disk, key, size cap, owner, bucket, actor) and how far
 * it got. A ticket is an audit record — the package moves it between states and never deletes it,
 * so it has no soft deletes. The bearer secret is not stored, only its sha256.
 *
 * @property string $id
 * @property string $token_hash
 * @property string $media_uuid
 * @property string $disk
 * @property string $path
 * @property bool $in_place
 * @property string $bucket_name
 * @property string|null $model_type
 * @property string|null $model_id
 * @property bool $draft
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string $file_name
 * @property int|null $declared_size
 * @property string|null $declared_mime
 * @property string|null $declared_checksum
 * @property int $max_size
 * @property string $visibility
 * @property array<string, mixed>|null $options
 * @property string|null $multipart_upload_id
 * @property int|null $part_size
 * @property int|null $part_count
 * @property DirectUploadStatus $status
 * @property string|null $failure_reason
 * @property int|null $media_id
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $claimed_at
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
final class MediaUpload extends Model
{
    /** @use HasFactory<MediaUploadFactory> */
    use HasFactory;

    use HasUlids;

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    public function getTable(): string
    {
        return MediaConfig::uploadsTableName();
    }

    public function method(): DirectUploadMethod
    {
        return $this->multipart_upload_id !== null
            ? DirectUploadMethod::Multipart
            : DirectUploadMethod::from((string) ($this->options['method'] ?? DirectUploadMethod::Put->value));
    }

    /** The option the ticket was issued with, or `$default`. */
    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'in_place' => 'boolean',
            'draft' => 'boolean',
            'declared_size' => 'integer',
            'max_size' => 'integer',
            'part_size' => 'integer',
            'part_count' => 'integer',
            'media_id' => 'integer',
            'options' => 'array',
            'status' => DirectUploadStatus::class,
            'expires_at' => 'datetime',
            'claimed_at' => 'datetime',
        ];
    }

    protected static function newFactory(): MediaUploadFactory
    {
        return MediaUploadFactory::new();
    }
}
