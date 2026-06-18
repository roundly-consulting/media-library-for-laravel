<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

use DateTimeInterface;
use RoundlyConsulting\MediaLibrary\Contracts\UrlGenerator;
use RoundlyConsulting\MediaLibrary\Models\Media;

/**
 * Decorates the {@see DefaultUrlGenerator}, rewriting PUBLIC URLs onto a CDN host (§9.4).
 *
 * Only public URLs are rewritten — private/temporary URLs must stay signed/presigned, so they pass
 * through to the wrapped generator untouched. Rewriting is limited to `media.cdn.disks` when that
 * list is non-empty, and a cache-bust `?v={updated_at}` param is appended when `media.cdn.cache_bust`
 * is on, so replaced media busts CDN caches automatically.
 */
final class CdnUrlGenerator implements UrlGenerator
{
    public function __construct(
        private readonly UrlGenerator $default,
    ) {}

    public function getUrl(Media $media, string $variant = ''): string
    {
        $url = $this->default->getUrl($media, $variant);

        if (! $this->shouldRewrite($media, $variant)) {
            return $url;
        }

        return $this->cacheBust($media, $this->rewriteHost($url));
    }

    public function getTemporaryUrl(Media $media, DateTimeInterface $expiry, string $variant = ''): string
    {
        // Temporary URLs are signed/presigned — never rewritten onto the CDN host.
        return $this->default->getTemporaryUrl($media, $expiry, $variant);
    }

    private function shouldRewrite(Media $media, string $variant): bool
    {
        $base = config('media.cdn.base_url');

        if (! is_string($base) || $base === '') {
            return false;
        }

        $disks = config('media.cdn.disks');

        if (is_array($disks) && $disks !== [] && ! in_array($media->diskFor($variant), $disks, true)) {
            return false;
        }

        return true;
    }

    private function rewriteHost(string $url): string
    {
        $base = config('media.cdn.base_url');
        $base = is_string($base) ? rtrim($base, '/') : '';

        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = parse_url($url, PHP_URL_QUERY);

        $rewritten = $base.$path;

        if (is_string($query) && $query !== '') {
            $rewritten .= '?'.$query;
        }

        return $rewritten;
    }

    private function cacheBust(Media $media, string $url): string
    {
        if (config('media.cdn.cache_bust') !== true) {
            return $url;
        }

        $version = $media->updated_at?->getTimestamp();

        if ($version === null) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'v='.$version;
    }
}
