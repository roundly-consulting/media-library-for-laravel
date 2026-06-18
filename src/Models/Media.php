<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Contracts\PathGenerator;
use RoundlyConsulting\MediaLibrary\Database\Factories\MediaFactory;

/**
 * @property int $id
 * @property string $uuid
 * @property string|null $model_type
 * @property int|null $model_id
 * @property string $bucket_name
 * @property string $name
 * @property string $file_name
 * @property string|null $mime_type
 * @property string|null $extension
 * @property string $disk
 * @property string|null $variants_disk
 * @property int $size
 * @property string $visibility
 * @property array<string, mixed>|null $custom_properties
 * @property array<string, bool>|null $generated_variants
 * @property string|null $checksum
 * @property int|null $width
 * @property int|null $height
 * @property array<string, string>|null $placeholders
 * @property string|null $draft_token
 * @property CarbonInterface|null $draft_expires_at
 * @property int|null $order_column
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 * @property CarbonInterface|null $deleted_at
 */
class Media extends Model
{
    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $guarded = [];

    public function getTable(): string
    {
        $table = config('media.table_name');

        return is_string($table) ? $table : 'media';
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'model_id' => 'integer',
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'order_column' => 'integer',
            'custom_properties' => 'array',
            'generated_variants' => 'array',
            'placeholders' => 'array',
            'draft_expires_at' => 'datetime',
        ];
    }

    protected static function newFactory(): MediaFactory
    {
        return MediaFactory::new();
    }

    /** @return MorphTo<Model, $this> */
    public function model(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @param  Builder<Media>  $query
     */
    public function scopeInBucket(Builder $query, string $bucket): void
    {
        $query->where('bucket_name', $bucket);
    }

    /**
     * @param  Builder<Media>  $query
     */
    public function scopeGlobal(Builder $query): void
    {
        $query->whereNull('model_type')->whereNull('model_id');
    }

    /**
     * @param  Builder<Media>  $query
     */
    public function scopeForModel(Builder $query, Model $model): void
    {
        $query->where('model_type', $model->getMorphClass())
            ->where('model_id', $model->getKey());
    }

    /**
     * @param  Builder<Media>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('order_column')->orderBy('id');
    }

    /**
     * @param  Builder<Media>  $query
     */
    public function scopeDrafts(Builder $query): void
    {
        $query->whereNotNull('draft_token');
    }

    public function getPath(): string
    {
        return $this->pathGenerator()->getPath($this).$this->file_name;
    }

    /** @return resource|null */
    public function getStream()
    {
        return Storage::disk($this->disk)->readStream($this->getPath());
    }

    public function getUrl(): string
    {
        return Storage::disk($this->disk)->url($this->getPath());
    }

    public function hasGeneratedVariant(string $name): bool
    {
        return ($this->generated_variants[$name] ?? false) === true;
    }

    public function getCustomProperty(string $key, mixed $default = null): mixed
    {
        return data_get($this->custom_properties, $key, $default);
    }

    public function setCustomProperty(string $key, mixed $value): self
    {
        $properties = $this->custom_properties ?? [];
        data_set($properties, $key, $value);
        $this->custom_properties = $properties;

        return $this;
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }

    private function pathGenerator(): PathGenerator
    {
        return app(PathGenerator::class);
    }
}
