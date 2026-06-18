<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Models;

use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Actions\CopyMediaAction;
use RoundlyConsulting\MediaLibrary\Actions\DeleteMediaAction;
use RoundlyConsulting\MediaLibrary\Actions\MoveMediaAction;
use RoundlyConsulting\MediaLibrary\Actions\ReplaceMediaAction;
use RoundlyConsulting\MediaLibrary\Contracts\FileNamer;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\MediaLibrary\Contracts\PathGenerator;
use RoundlyConsulting\MediaLibrary\Contracts\UrlGenerator;
use RoundlyConsulting\MediaLibrary\Database\Factories\MediaFactory;
use RoundlyConsulting\MediaLibrary\Exceptions\ChecksumMismatch;
use RoundlyConsulting\MediaLibrary\Placeholders\PlaceholderDataUri;
use RoundlyConsulting\MediaLibrary\Support\Checksum;
use RoundlyConsulting\MediaLibrary\Variants\ResponsiveImageGenerator;
use RoundlyConsulting\MediaLibrary\Variants\Variant;
use RoundlyConsulting\MediaLibrary\Variants\VariantResolver;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
 * @property string|null $path
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

    public function getPath(string $variant = ''): string
    {
        if ($variant === '') {
            // A stored `path` (set on add, or pointed at a shared original on dedup) wins over the
            // uuid-derived layout so a deduplicated row resolves to the canonical file's bytes.
            if (is_string($this->path) && $this->path !== '') {
                return $this->path;
            }

            return $this->pathGenerator()->getPath($this).$this->file_name;
        }

        return $this->pathGenerator()->getPathForVariants($this).$this->variantFileName($variant);
    }

    /** The variants directory for this media, used when relocating stored variant files by name. */
    public function getPathForVariantsDirectory(): string
    {
        return $this->pathGenerator()->getPathForVariants($this);
    }

    /** @return resource|null */
    public function getStream(string $variant = '')
    {
        $this->guardChecksumOnRead($variant);

        return Storage::disk($this->diskFor($variant))->readStream($this->getPath($variant));
    }

    public function getUrl(string $variant = ''): string
    {
        return $this->urlGenerator()->getUrl($this, $variant);
    }

    public function getTemporaryUrl(DateTimeInterface $expiry, string $variant = ''): string
    {
        return $this->urlGenerator()->getTemporaryUrl($this, $expiry, $variant);
    }

    public function isPublic(): bool
    {
        return $this->visibility !== 'private';
    }

    public function isPrivate(): bool
    {
        return $this->visibility === 'private';
    }

    /** Stream the media inline (range-aware) straight from its disk. */
    public function toResponse(Request $request): StreamedResponse
    {
        $variant = $this->variantFromRequest($request);

        if ($this->wantsDownload($request)) {
            return $this->toDownloadResponse(null, $variant);
        }

        $this->guardChecksumOnRead($variant);

        if ($request->headers->has('Range')) {
            return $this->rangeResponse($request, $variant);
        }

        return Storage::disk($this->diskFor($variant))
            ->response($this->getPath($variant), $this->file_name, $this->streamHeaders());
    }

    /** Force a download (HTTP attachment) of the media from its disk. */
    public function toDownloadResponse(?string $name = null, string $variant = ''): StreamedResponse
    {
        $this->guardChecksumOnRead($variant);

        return Storage::disk($this->diskFor($variant))
            ->download($this->getPath($variant), $name ?? $this->file_name);
    }

    public function hasGeneratedVariant(string $name): bool
    {
        return ($this->generated_variants[$name] ?? false) === true;
    }

    /**
     * Relocate this media (original + variants) — across disks, and/or to a different owning model
     * and bucket (including model ↔ global) — deleting the source files. Returns this media.
     */
    public function move(HasMedia|Model|null $toModel = null, string $bucket = 'default', ?string $disk = null): self
    {
        return app(MoveMediaAction::class)->execute($this, $toModel, $bucket, $disk);
    }

    /**
     * Duplicate this media into a new row with a fresh uuid, optionally onto a different disk and/or
     * under a different owning model and bucket. The source files are preserved.
     */
    public function copy(HasMedia|Model|null $toModel = null, string $bucket = 'default', ?string $disk = null): self
    {
        return app(CopyMediaAction::class)->execute($this, $toModel, $bucket, $disk);
    }

    /** Move only the original's bytes to another disk, keeping ownership and bucket. */
    public function moveToDisk(string $disk): self
    {
        return app(MoveMediaAction::class)->toDisk($this, $disk);
    }

    /** Move only the variant files to another disk; the original stays put. */
    public function moveVariantsToDisk(string $disk): self
    {
        return app(MoveMediaAction::class)->variantsToDisk($this, $disk);
    }

    /** Permanently delete this media: its row and its stored files (original + variants). */
    public function deleteWithFiles(): void
    {
        app(DeleteMediaAction::class)->execute($this);
    }

    /**
     * Replace the underlying original with new bytes, keeping this media's `id`, `uuid`, and URL.
     * Checksum, size, mime, extension, dimensions, and placeholders are recomputed and variants
     * are regenerated. Returns this media.
     */
    public function replace(string|UploadedFile $file): self
    {
        return app(ReplaceMediaAction::class)->execute($this, $file);
    }

    /**
     * Resolve the variant definitions that apply to this media via its owning model's bucket.
     *
     * @return list<Variant>
     */
    public function resolveVariants(): array
    {
        return app(VariantResolver::class)->forMedia($this);
    }

    /** The disk a given variant (or the original) lives on. */
    public function diskFor(string $variant = ''): string
    {
        if ($variant === '') {
            return $this->disk;
        }

        return $this->variants_disk ?? $this->disk;
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

    /**
     * The stored LQIP placeholder map, e.g. `['thumbhash' => '…', 'blurhash' => '…']`.
     *
     * @return array<string, string>
     */
    public function placeholder(): array
    {
        /** @var array<string, string> */
        return $this->placeholders ?? [];
    }

    /** The stored ThumbHash, or null when none was computed (e.g. non-image, or toggled off). */
    public function thumbhash(): ?string
    {
        $value = $this->placeholder()['thumbhash'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** The stored Blurhash, or null when none was computed. */
    public function blurhash(): ?string
    {
        $value = $this->placeholder()['blurhash'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * A tiny decoded-placeholder `data:` URI (server-side decode of ThumbHash, else Blurhash),
     * suitable as a blur-up background. Null when this media carries no placeholder.
     */
    public function placeholderDataUri(): ?string
    {
        if ($this->placeholder() === []) {
            return null;
        }

        return app(PlaceholderDataUri::class)->fromPlaceholders($this->placeholder());
    }

    /**
     * A `srcset` string for the bucket's responsive width ladder, ascending — e.g.
     * `"…/320.webp 320w, …/640.webp 640w"`. Only generated widths are included; empty when none.
     */
    public function srcset(string $base = ''): string
    {
        return app(ResponsiveImageGenerator::class)->srcset($this, $base);
    }

    /**
     * A full `<img>` tag with `src` (smallest generated width or the base), `srcset`, optional
     * `sizes`/`alt`, and the LQIP placeholder as an inline blur-up background.
     *
     * @param  array<string, string>  $attributes
     */
    public function responsiveImage(string $base = '', array $attributes = []): string
    {
        return app(ResponsiveImageGenerator::class)->imageTag($this, $base, $attributes);
    }

    /**
     * Re-hash the stored original and compare it to the recorded baseline.
     *
     * Returns false when no baseline was recorded, the file is missing, or the bytes drifted.
     */
    public function verifyIntegrity(): bool
    {
        if (! is_string($this->checksum) || $this->checksum === '') {
            return false;
        }

        $actual = app(Checksum::class)->forStoredOriginal($this);

        return $actual !== null && hash_equals($this->checksum, $actual);
    }

    private function variantFileName(string $variant): string
    {
        return $this->fileNamer()->variantFileName($variant, $this->variantExtension($variant));
    }

    private function variantExtension(string $variant): string
    {
        foreach ($this->resolveVariants() as $definition) {
            if ($definition->name === $variant && $definition->getFormat() !== null) {
                return $definition->getFormat();
            }
        }

        $extension = $this->extension;

        if ($extension === null || $extension === '') {
            return 'jpg';
        }

        return $extension === 'jpeg' ? 'jpg' : $extension;
    }

    /**
     * Serve a single byte range with a 206 response, seeking the disk stream rather than buffering
     * the whole file. Unsatisfiable ranges yield 416.
     */
    private function rangeResponse(Request $request, string $variant): StreamedResponse
    {
        $disk = $this->diskFor($variant);
        $path = $this->getPath($variant);
        $size = Storage::disk($disk)->size($path);

        $range = $this->parseRange((string) $request->headers->get('Range'), $size);

        if ($range === null) {
            $response = new StreamedResponse(status: 416);
            $response->headers->set('Content-Range', "bytes */{$size}");

            return $response;
        }

        [$start, $end] = $range;
        $length = $end - $start + 1;

        $headers = $this->streamHeaders();
        $headers['Content-Length'] = (string) $length;
        $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        $headers['Accept-Ranges'] = 'bytes';

        return new StreamedResponse(function () use ($disk, $path, $start, $length): void {
            $stream = Storage::disk($disk)->readStream($path);

            if ($stream === null) {
                return;
            }

            fseek($stream, $start);
            $remaining = $length;

            while ($remaining > 0 && ! feof($stream)) {
                $chunk = fread($stream, (int) min(8192, $remaining));

                if ($chunk === false) {
                    break;
                }

                echo $chunk;
                $remaining -= strlen($chunk);
            }

            fclose($stream);
        }, 206, $headers);
    }

    /**
     * Parse a single `bytes=start-end` range against the file size.
     *
     * @return array{0: int, 1: int}|null [start, end] inclusive, or null if unsatisfiable
     */
    private function parseRange(string $header, int $size): ?array
    {
        if (! preg_match('/^bytes=(\d*)-(\d*)$/', $header, $matches)) {
            return null;
        }

        [$rawStart, $rawEnd] = [$matches[1], $matches[2]];

        if ($rawStart === '' && $rawEnd === '') {
            return null;
        }

        if ($rawStart === '') {
            $start = max(0, $size - (int) $rawEnd);
            $end = $size - 1;
        } else {
            $start = (int) $rawStart;
            $end = $rawEnd === '' ? $size - 1 : (int) $rawEnd;
        }

        $end = min($end, $size - 1);

        if ($start > $end || $start >= $size) {
            return null;
        }

        return [$start, $end];
    }

    /**
     * Re-hash the original on read when `media.verify_checksum_on_read` is enabled, throwing
     * {@see ChecksumMismatch} on drift. Only the original carries a recorded checksum, so variant
     * reads are not verified.
     */
    private function guardChecksumOnRead(string $variant): void
    {
        if ($variant !== '' || config('media.verify_checksum_on_read') !== true) {
            return;
        }

        if (! is_string($this->checksum) || $this->checksum === '') {
            return;
        }

        if (! $this->verifyIntegrity()) {
            throw ChecksumMismatch::forMedia($this->uuid);
        }
    }

    private function variantFromRequest(Request $request): string
    {
        $variant = $request->route('variant');

        return is_string($variant) ? $variant : '';
    }

    private function wantsDownload(Request $request): bool
    {
        return $request->boolean('download');
    }

    /** @return array<string, string> */
    private function streamHeaders(): array
    {
        $headers = [];

        if (is_string($this->mime_type) && $this->mime_type !== '') {
            $headers['Content-Type'] = $this->mime_type;
        }

        return $headers;
    }

    private function urlGenerator(): UrlGenerator
    {
        return app(UrlGenerator::class);
    }

    private function pathGenerator(): PathGenerator
    {
        return app(PathGenerator::class);
    }

    private function fileNamer(): FileNamer
    {
        return app(FileNamer::class);
    }
}
