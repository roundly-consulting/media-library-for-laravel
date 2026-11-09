<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Uploads;

use Illuminate\Support\Str;
use RoundlyConsulting\MediaLibrary\Models\MediaUpload;
use SensitiveParameter;

/**
 * A ticket's bearer token: `{ticket ulid}.{secret}`. The secret is 48 random characters (about
 * 285 bits); only its sha256 is stored, so a leaked table cannot adopt anything. A lookup digest
 * of a high-entropy random secret — not a password, so no slow hash.
 *
 * @internal
 */
final class UploadToken
{
    public static function secret(): string
    {
        return Str::random(48);
    }

    public static function compose(string $id, #[SensitiveParameter] string $secret): string
    {
        return $id.'.'.$secret;
    }

    public static function digest(#[SensitiveParameter] string $secret): string
    {
        return hash('sha256', $secret);
    }

    /** The ticket the token belongs to, or null — compared in constant time. */
    public static function find(#[SensitiveParameter] string $token): ?MediaUpload
    {
        $parts = explode('.', $token, 2);

        if (count($parts) !== 2 || ! Str::isUlid($parts[0]) || $parts[1] === '') {
            return null;
        }

        $upload = MediaUpload::query()->find(strtolower($parts[0]));

        return $upload !== null && hash_equals($upload->token_hash, self::digest($parts[1])) ? $upload : null;
    }
}
