<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

use RoundlyConsulting\MediaLibrary\Exceptions\RemoteFileRejected;

/**
 * Where a redirect leads: which statuses are followed and how a `Location` is resolved (RFC 3986
 * §5.2). The contract fixture (`tests/Fixtures/contract/blocked-networks.json`, `redirects`) pins
 * both for the Node twin too.
 *
 * The result is the next hop as the guard will judge it: an absolute `Location` comes back exactly
 * as the server wrote it, so the guard sees (and refuses) a numeric or malformed host instead of a
 * normalised one. A relative reference with a backslash or a control character is refused here:
 * WHATWG parsers read `\` as `/` and strip a tab, so `/\evil.example/` would lead elsewhere there.
 *
 * @internal
 */
final class RedirectLocation
{
    /** The statuses whose `Location` is followed; any other answer is the final one. */
    public const array STATUSES = [301, 302, 303, 307, 308];

    /**
     * The URL `$location` leads to from `$base` (a URL the guard rebuilt, without a fragment).
     *
     * @throws RemoteFileRejected (`invalid_url`) for a relative reference no parser agrees on
     */
    public static function resolve(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $location) === 1) {
            return $location;
        }

        if (preg_match('/[\x00-\x1F\x7F\\\\]/', $location) === 1) {
            throw RemoteFileRejected::invalidUrl($location);
        }

        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'http';
        $authority = $parts['host'] ?? '';
        $authority = str_contains($authority, ':') && ! str_starts_with($authority, '[') ? '['.$authority.']' : $authority;
        $authority .= isset($parts['port']) ? ':'.$parts['port'] : '';

        if (str_starts_with($location, '//')) {
            return $scheme.':'.$location;
        }

        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?'.$parts['query'] : '';

        if (str_starts_with($location, '#')) {
            // The same document: base path and query kept, as RFC 3986 §5.2.2 says.
            return $scheme.'://'.$authority.$path.$query.$location;
        }

        if (str_starts_with($location, '?')) {
            return $scheme.'://'.$authority.$path.$location;
        }

        if (! str_starts_with($location, '/')) {
            $location = substr($path, 0, (int) strrpos($path, '/') + 1).$location;
        }

        return $scheme.'://'.$authority.self::removeDotSegments($location);
    }

    private static function removeDotSegments(string $reference): string
    {
        $query = '';
        $cut = strcspn($reference, '?#');

        if ($cut < strlen($reference)) {
            $query = substr($reference, $cut);
            $reference = substr($reference, 0, $cut);
        }

        $output = [];

        foreach (explode('/', $reference) as $segment) {
            if ($segment === '..') {
                if (count($output) > 1) {
                    array_pop($output);
                }
            } elseif ($segment !== '.') {
                $output[] = $segment;
            }
        }

        $path = implode('/', $output);

        if (str_ends_with($reference, '/..') || str_ends_with($reference, '/.')) {
            $path .= '/';
        }

        return ($path === '' ? '/' : $path).$query;
    }
}
