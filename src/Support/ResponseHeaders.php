<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

use Illuminate\Support\Str;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\TemporaryUrlOptions;
use RoundlyConsulting\MediaLibrary\Enums\ContentDisposition;
use RoundlyConsulting\MediaLibrary\Models\Media;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\Mime\MimeTypes;

/**
 * The one rule for how a media file is served — by the streaming route and `Media::toResponse()`,
 * and by a temporary URL that asks the storage origin for response headers: inline only for a type
 * a browser renders without running anything, an attachment for everything else.
 *
 * @internal
 */
final class ResponseHeaders
{
    /** Types a browser renders without running anything, besides every `audio/*` and `video/*`. */
    public const array INLINE_SAFE_TYPES = [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp',
        'image/x-ms-bmp', 'image/tiff', 'image/heic', 'image/heif', 'image/x-icon',
        'image/vnd.microsoft.icon', 'application/pdf', 'text/plain',
    ];

    /** Locks a streamed file into a sandbox, so even a mislabelled one cannot script the app origin. */
    public const string SANDBOX_POLICY = "sandbox; default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'";

    /**
     * The type of the file served for `$variant`: a variant's own output format, else the
     * original's recorded (sniffed) mime type.
     */
    public static function mimeType(Media $media, string $variant): ?string
    {
        $generated = $media->generatedVariant($variant);

        if ($generated !== null && $generated->format !== '') {
            return MimeTypes::getDefault()->getMimeTypes($generated->format)[0] ?? null;
        }

        return is_string($media->mime_type) && $media->mime_type !== '' ? $media->mime_type : null;
    }

    /** The name of the file served for `$variant`. */
    public static function fileName(Media $media, string $variant): string
    {
        return $media->generatedVariant($variant)->fileName ?? $media->file_name;
    }

    public static function isInlineSafe(?string $mimeType): bool
    {
        $mimeType = strtolower(trim(explode(';', (string) $mimeType)[0]));

        return in_array($mimeType, self::INLINE_SAFE_TYPES, true)
            || str_starts_with($mimeType, 'audio/')
            || str_starts_with($mimeType, 'video/');
    }

    /**
     * The disposition a response gets: the one asked for, else the rule — but `inline` only for
     * an inline-safe type.
     */
    public static function disposition(?ContentDisposition $requested, ?string $mimeType): ContentDisposition
    {
        if (! self::isInlineSafe($mimeType)) {
            return ContentDisposition::Attachment;
        }

        return $requested ?? ContentDisposition::Inline;
    }

    /**
     * The response overrides a presigned GET carries (S3's `ResponseContentDisposition` and
     * `ResponseContentType`), passed as the `$options` of the disk's `temporaryUrl()`.
     *
     * @return array<string, string>
     */
    public static function presignOptions(Media $media, string $variant, TemporaryUrlOptions $options): array
    {
        $type = $options->contentType ?? self::mimeType($media, $variant);
        $name = FileNames::sanitize($options->fileName ?? self::fileName($media, $variant));

        $presign = ['ResponseContentDisposition' => self::contentDisposition(self::disposition($options->disposition, $type), $name)];

        if ($type !== null) {
            $presign['ResponseContentType'] = $type;
        }

        return $presign;
    }

    /**
     * The `Content-Disposition` a stored object carries once adopted from a direct upload: the
     * rule for its own type, under its own name.
     */
    public static function storedDisposition(Media $media): string
    {
        $type = self::mimeType($media, '');

        return self::contentDisposition(self::disposition(null, $type), FileNames::sanitize(self::fileName($media, '')));
    }

    private static function contentDisposition(ContentDisposition $disposition, string $name): string
    {
        return HeaderUtils::makeDisposition($disposition->value, $name, self::asciiFallback($name));
    }

    /** The plain-ASCII `filename=` beside the UTF-8 `filename*=` (the way Laravel's download names it). */
    private static function asciiFallback(string $name): string
    {
        $ascii = str_replace(['%', '/', '\\', '"'], '', Str::ascii($name));

        return $ascii === '' ? 'file' : $ascii;
    }
}
