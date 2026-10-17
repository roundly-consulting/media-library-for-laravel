<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFetchFailed;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;

/**
 * Validates a URL a user hands in for a server-side fetch: it passes when
 * `MediaLibrary::remote()->vet()` accepts it, and otherwise fails with a translated message for
 * the reason (`media::validation.remote_url.<reason>`).
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
            $fail('media::validation.remote_url.invalid_url')->translate();

            return;
        }

        try {
            app(MediaLibraryManager::class)->remote()->vet($value);
        } catch (RemoteFetchFailed $exception) {
            $fail('media::validation.remote_url.'.$exception->reason()->value)->translate();
        }
    }
}
