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
use RoundlyConsulting\MediaLibrary\Contracts\FileNamer;
use RoundlyConsulting\MediaLibrary\Contracts\PathGenerator;
use RoundlyConsulting\MediaLibrary\Contracts\UrlGenerator;
use RoundlyConsulting\MediaLibrary\Database\Factories\MediaFactory;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\GeneratedVariant;
use RoundlyConsulting\MediaLibrary\Exceptions\ChecksumMismatch;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;
use RoundlyConsulting\MediaLibrary\Placeholders\PlaceholderDataUri;
use RoundlyConsulting\MediaLibrary\Support\Checksum;
use RoundlyConsulting\MediaLibrary\Variants\ResponsiveImageGenerator;
use RoundlyConsulting\MediaLibrary\Variants\Variant;
use RoundlyConsulting\MediaLibrary\Variants\VariantResolver;
use RoundlyConsulting\PackageToolkit\Support\Config;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Mime\MimeTypes;

/**
 * @property int $id
 * @property string $uuid
 * @property string|null $model_type
 * @property int|string|null $model_id
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
 * @property array<string, array{file_name: string, format: string, disk: string}>|null $generated_variants
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

    /**
     * Types a browser may render inline without running anything; everything else (HTML, SVG,
     * XML, scripts, unknown types) is streamed as an attachment.
     */
    private const INLINE_SAFE_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp',
        'image/x-ms-bmp', 'image/tiff', 'image/heic', 'image/heif', 'image/x-icon',
        'image/vnd.microsoft.icon', 'application/pdf', 'text/plain',
    ];

    /** Locks a streamed file into a sandbox, so even a mislabelled one cannot script the app origin. */
    private const SANDBOX_POLICY = "sandbox; default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'";

    protected $guarded = [];

    /** The draft token is a bearer secret: whoever holds it can bind the draft to their own model. */
    protected $hidden = ['draft_token'];

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
        // An unbound draft has no owner either, but it is not global media: it belongs to whoever
        // holds its token until it is bound (or pruned).
        $query->whereNull('model_type')->whereNull('model_id')->whereNull('draft_token');
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

        $fileName = $this->generatedVariant($variant)->fileName ?? $this->variantFileName($variant);

        return $this->pathGenerator()->getPathForVariants($this).$fileName;
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

    /**
     * Stream the media inline (range-aware) straight from its disk.
     *
     * Only types a browser renders without running anything are served inline; HTML, SVG, XML and
     * any other type are sent as an attachment. Every response carries `nosniff` and a sandboxing
     * CSP, so a file can never execute script on the application's origin.
     */
    public function toResponse(Request $request): StreamedResponse
    {
        $variant = $this->variantFromRequest($request);

        if ($this->wantsDownload($request) || ! $this->isInlineSafe($variant)) {
            return $this->toDownloadResponse(null, $variant);
        }

        $this->guardChecksumOnRead($variant);

        if ($request->headers->has('Range')) {
            $ranged = $this->rangeResponse($request, $variant);

            if ($ranged !== null) {
                return $ranged;
            }
        }

        return Storage::disk($this->diskFor($variant))
            ->response($this->getPath($variant), $this->responseFileName($variant), $this->streamHeaders($variant));
    }

    /** Force a download (HTTP attachment) of the media from its disk. */
    public function toDownloadResponse(?string $name = null, string $variant = ''): StreamedResponse
    {
        $this->guardChecksumOnRead($variant);

        return Storage::disk($this->diskFor($variant))
            ->download($this->getPath($variant), $name ?? $this->responseFileName($variant), $this->streamHeaders($variant));
    }

    public function hasGeneratedVariant(string $name): bool
    {
        return $this->generatedVariant($name) !== null;
    }

    /**
     * What was written for each generated variant, keyed by variant name.
     *
     * @internal
     *
     * @return array<string, GeneratedVariant>
     */
    public function generatedVariants(): array
    {
        $records = [];

        foreach ($this->generated_variants ?? [] as $name => $stored) {
            $record = GeneratedVariant::fromStored($stored);

            if ($record !== null) {
                $records[(string) $name] = $record;
            }
        }

        return $records;
    }

    /** @internal what was written for one variant, or null when it has not been generated */
    public function generatedVariant(string $name): ?GeneratedVariant
    {
        return $name === '' ? null : GeneratedVariant::fromStored($this->generated_variants[$name] ?? null);
    }

    /** @internal record the file a variant was just written to (unsaved) */
    public function recordGeneratedVariant(string $name, GeneratedVariant $variant): void
    {
        $records = $this->generated_variants ?? [];
        $records[$name] = $variant->toArray();

        $this->generated_variants = $records;
    }

    /** @internal forget a variant's record (unsaved); its file is the caller's to remove */
    public function forgetGeneratedVariant(string $name): void
    {
        $records = $this->generated_variants ?? [];
        unset($records[$name]);

        $this->generated_variants = $records;
    }

    /**
     * Relocate this media (original + variants) — across disks, and/or to a different owning model
     * and bucket (including model ↔ global) — deleting the source files. Returns this media.
     */
    public function move(?Model $to = null, string $bucket = 'default', ?string $disk = null): self
    {
        return $this->mediaLibrary()->move($this, $to, $bucket, $disk);
    }

    /**
     * Duplicate this media into a new row with a fresh uuid, optionally onto a different disk and/or
     * under a different owning model and bucket. The source files are preserved.
     */
    public function copy(?Model $to = null, string $bucket = 'default', ?string $disk = null): self
    {
        return $this->mediaLibrary()->copy($this, $to, $bucket, $disk);
    }

    /** Move only the original's bytes to another disk, keeping ownership and bucket. */
    public function moveToDisk(string $disk): self
    {
        return $this->mediaLibrary()->moveToDisk($this, $disk);
    }

    /** Move only the variant files to another disk; the original stays put. */
    public function moveVariantsToDisk(string $disk): self
    {
        return $this->mediaLibrary()->moveVariantsToDisk($this, $disk);
    }

    /** Permanently delete this media: its row and its stored files (original + variants). */
    public function deleteWithFiles(): void
    {
        $this->mediaLibrary()->delete($this);
    }

    /**
     * Replace the underlying original with new bytes, keeping this media's `id`, `uuid`, and URL.
     * Checksum, size, mime, extension, dimensions, and placeholders are recomputed and variants
     * are regenerated. Returns this media.
     */
    public function replace(string|UploadedFile $file): self
    {
        return $this->mediaLibrary()->replace($this, $file);
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

    /**
     * The disk a given variant (or the original) lives on: where a generated variant was actually
     * written, else where it would be written (its own `storeOnDisk()`, the media's variants disk,
     * the original's disk).
     */
    public function diskFor(string $variant = ''): string
    {
        if ($variant === '') {
            return $this->disk;
        }

        $generated = $this->generatedVariant($variant);

        if ($generated !== null) {
            return $generated->disk;
        }

        return $this->variantDefinition($variant)?->disk() ?? $this->variants_disk ?? $this->disk;
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

    /** Where an un-generated variant WOULD be written — the same derivation the generator uses. */
    private function variantFileName(string $variant): string
    {
        $source = (string) $this->extension;
        $format = $this->variantDefinition($variant)?->outputFormat($source) ?? Variant::inheritedFormat($source);

        return $this->fileNamer()->variantFileName($variant, $format);
    }

    private function variantDefinition(string $variant): ?Variant
    {
        foreach ($this->resolveVariants() as $definition) {
            if ($definition->name === $variant) {
                return $definition;
            }
        }

        return null;
    }

    /**
     * Serve a single byte range with a 206 response, seeking the disk stream rather than buffering
     * the whole file. A header this cannot parse — another unit, several ranges, `last < first`,
     * garbage — is ignored (null: serve the whole file, as RFC 9110 allows); a well-formed range
     * past the end of the file yields 416.
     */
    private function rangeResponse(Request $request, string $variant): ?StreamedResponse
    {
        $header = trim((string) $request->headers->get('Range'));

        if (! preg_match('/^bytes=(\d*)-(\d*)$/', $header, $matches) || $matches[1].$matches[2] === '') {
            return null;
        }

        if ($matches[1] !== '' && $matches[2] !== '' && (int) $matches[2] < (int) $matches[1]) {
            return null;
        }

        $disk = $this->diskFor($variant);
        $path = $this->getPath($variant);
        $size = Storage::disk($disk)->size($path);

        $range = $this->satisfiableRange($matches[1], $matches[2], $size);

        if ($range === null) {
            // The callback is required: a StreamedResponse without one throws when it is sent.
            $response = new StreamedResponse(static function (): void {}, 416);
            $response->headers->set('Content-Range', "bytes */{$size}");

            return $response;
        }

        [$start, $end] = $range;
        $length = $end - $start + 1;

        $headers = $this->streamHeaders($variant);
        $headers['Content-Length'] = (string) $length;
        $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
        $headers['Accept-Ranges'] = 'bytes';

        return new StreamedResponse(function () use ($disk, $path, $start, $length): void {
            $stream = Storage::disk($disk)->readStream($path);

            if ($stream === null) {
                return;
            }

            self::copyRange($stream, $start, $length);
        }, 206, $headers);
    }

    /**
     * Echo `$length` bytes from `$stream` starting at `$start`, then close the stream. Extracted so
     * the range-copy guards (a short/unreadable stream) are unit-testable without HTTP plumbing.
     *
     * @param  resource  $stream
     */
    public static function copyRange($stream, int $start, int $length): void
    {
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
    }

    /**
     * Resolve a well-formed `bytes=start-end` range against the file size.
     *
     * @return array{0: int, 1: int}|null [start, end] inclusive, or null if unsatisfiable
     */
    private function satisfiableRange(string $rawStart, string $rawEnd, int $size): ?array
    {
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
        if ($variant !== '' || ! Config::boolean('media.verify_checksum_on_read')) {
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

    /**
     * The type of the file a response serves: a variant's own output format, else the original's
     * recorded (sniffed) mime type.
     */
    private function responseMimeType(string $variant): ?string
    {
        $generated = $this->generatedVariant($variant);

        if ($generated !== null && $generated->format !== '') {
            return MimeTypes::getDefault()->getMimeTypes($generated->format)[0] ?? null;
        }

        return is_string($this->mime_type) && $this->mime_type !== '' ? $this->mime_type : null;
    }

    private function isInlineSafe(string $variant): bool
    {
        $mimeType = (string) $this->responseMimeType($variant);

        return in_array($mimeType, self::INLINE_SAFE_TYPES, true)
            || str_starts_with($mimeType, 'audio/')
            || str_starts_with($mimeType, 'video/');
    }

    private function responseFileName(string $variant): string
    {
        return $this->generatedVariant($variant)->fileName ?? $this->file_name;
    }

    /** @return array<string, string> */
    private function streamHeaders(string $variant): array
    {
        $headers = ['X-Content-Type-Options' => 'nosniff'];

        $mimeType = $this->responseMimeType($variant);

        if ($mimeType !== null) {
            $headers['Content-Type'] = $mimeType;
        }

        // A browser's PDF viewer refuses to run inside a CSP sandbox; PDF is inline-safe without it.
        if ($mimeType !== 'application/pdf') {
            $headers['Content-Security-Policy'] = self::SANDBOX_POLICY;
        }

        return $headers;
    }

    /** The (possibly faked) manager every mutation above goes through. */
    private function mediaLibrary(): MediaLibraryManager
    {
        return app(MediaLibraryManager::class);
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
