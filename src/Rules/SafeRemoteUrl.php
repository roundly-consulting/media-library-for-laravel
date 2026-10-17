<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFetchFailed;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;

/**
 * Validates a URL a user hands in for a server-side fetch: it passes when
 * `MediaLibrary::remote()->vet()` accepts it, and otherwise fails with one translated message
 * (`media::validation.remote_url`), whatever the reason. Telling "private" from "not found" would
 * tell a user which internal names exist; call `vet()` yourself for the exact reason.
 *
 * ```php
 * 'source' => ['required', 'string', 'max:2048', new SafeRemoteUrl],
 * ```
 *
 * The URL is checked again when it is fetched — DNS can change in between.
 */
final class SafeRemoteUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('media::validation.remote_url')->translate();

            return;
        }

        try {
            app(MediaLibraryManager::class)->remote()->vet($value);
        } catch (RemoteFetchFailed) {
            $fail('media::validation.remote_url')->translate();
        }
    }
}
