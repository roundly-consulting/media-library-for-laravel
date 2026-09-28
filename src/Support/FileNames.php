<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Support;

use Symfony\Component\Mime\MimeTypes;

/**
 * Turns an untrusted file name — a client's upload name, a URL's last segment, or a
 * `usingFileName()` override — into a safe stored name.
 *
 * Two guarantees:
 *
 *  - **One path segment.** Directory parts, separators and control characters are stripped, so a
 *    name can never climb out of the media's directory (`../other-uuid/victim.png`).
 *  - **No lying active extension.** An extension a web server executes (`.php`, `.phtml`, …) is
 *    never stored, and one a browser renders as active content (`.html`, `.svg`, `.js`, …) is
 *    kept only when the sniffed bytes really are that type. A PNG polyglot uploaded as `x.html`
 *    is stored as `x.png`; a truthful or harmless extension (`IMG_0001.JPG`, `data.csv`) is kept.
 *
 * @internal
 */
final class FileNames
{
    /** Extensions a web server may execute. Never stored, whatever the bytes are. */
    private const SERVER_EXECUTABLE = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'pht', 'phar', 'phps', 'pgif',
        'shtml', 'shtm', 'stm', 'cgi', 'fcgi', 'pl', 'py', 'rb', 'sh', 'bash',
        'asp', 'aspx', 'ascx', 'ashx', 'asmx', 'jsp', 'jspx', 'cfm', 'cfc',
    ];

    /** Extensions a browser renders as active content. Kept only when the bytes match. */
    private const BROWSER_ACTIVE = [
        'html', 'htm', 'xhtml', 'xht', 'mht', 'mhtml', 'svg', 'svgz', 'xml', 'xsl', 'xslt',
        'js', 'mjs', 'cjs', 'swf', 'hta',
    ];

    /** Longest stored name, in bytes — the `file_name` column is a 255-character string. */
    private const MAX_LENGTH = 200;

    /** A single, separator-free path segment, or `file` when nothing usable is left. */
    public static function sanitize(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $slash = strrpos($name, '/');

        if ($slash !== false) {
            $name = substr($name, $slash + 1);
        }

        $name = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $name);
        $name = str_replace(['#', ' ', ':', '*', '?', '"', '<', '>', '|', '%'], '-', $name);
        $name = ltrim($name, '.');

        return self::truncate($name === '' ? 'file' : $name);
    }

    /**
     * `$name` with an extension that tells the truth about `$mimeType` wherever the difference
     * matters for safety (see the class docblock). `$name` is expected to be sanitized already.
     */
    public static function conform(string $name, ?string $mimeType): string
    {
        [$base, $extension] = self::split($name);
        $lower = strtolower($extension);

        $base = self::neutraliseInnerExtensions($base);
        $valid = $mimeType === null ? [] : array_values(MimeTypes::getDefault()->getExtensions($mimeType));
        $truthful = in_array($lower, $valid, true);

        if ($lower !== '' && ! in_array($lower, self::SERVER_EXECUTABLE, true)
            && ($truthful || ! in_array($lower, self::BROWSER_ACTIVE, true))) {
            return self::join($base, $extension);
        }

        return self::join($base, self::safeExtensionFor($mimeType, $valid, keepEmpty: $lower === ''));
    }

    /** The lower-cased extension of a stored name, or null when it has none. */
    public static function extensionOf(string $name): ?string
    {
        $extension = strtolower(self::split($name)[1]);

        return $extension === '' ? null : $extension;
    }

    /**
     * The extension to store for `$mimeType`: its primary known extension unless that is itself
     * executable, else a neutral `txt`/`bin`. With `$keepEmpty` (the name had no extension), an
     * unknown type stays extension-less rather than getting an invented one.
     *
     * @param  list<string>  $valid
     */
    private static function safeExtensionFor(?string $mimeType, array $valid, bool $keepEmpty): string
    {
        $candidate = $valid[0] ?? null;

        if ($candidate !== null && ! in_array($candidate, self::SERVER_EXECUTABLE, true)) {
            return $candidate;
        }

        if ($keepEmpty && $candidate === null) {
            return '';
        }

        return str_starts_with((string) $mimeType, 'text/') ? 'txt' : 'bin';
    }

    /** `shell.php.png` → `shell-php.png`: some servers execute a file by ANY of its extensions. */
    private static function neutraliseInnerExtensions(string $base): string
    {
        $segments = explode('.', $base);

        foreach (array_slice($segments, 1) as $segment) {
            $segment = strtolower($segment);

            if (in_array($segment, self::SERVER_EXECUTABLE, true) || in_array($segment, self::BROWSER_ACTIVE, true)) {
                return str_replace('.', '-', $base);
            }
        }

        return $base;
    }

    /** @return array{0: string, 1: string} [base, extension] — the extension without its dot */
    private static function split(string $name): array
    {
        $dot = strrpos($name, '.');

        if ($dot === false || $dot === 0) {
            return [$name, ''];
        }

        return [substr($name, 0, $dot), substr($name, $dot + 1)];
    }

    private static function join(string $base, string $extension): string
    {
        return $extension === '' ? $base : $base.'.'.$extension;
    }

    private static function truncate(string $name): string
    {
        if (strlen($name) <= self::MAX_LENGTH) {
            return $name;
        }

        [$base, $extension] = self::split($name);
        $extension = substr($extension, 0, 20);
        $room = self::MAX_LENGTH - ($extension === '' ? 0 : strlen($extension) + 1);

        return self::join(mb_strcut($base, 0, max(1, $room)), $extension);
    }
}
