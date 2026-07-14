<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

/**
 * The config contract, pinned in BOTH directions.
 *
 * A key the code reads but the package never ships is unreachable — the feature is configurable
 * only in theory. A key the package ships but no code reads is a documented feature that silently
 * does nothing. Both have shipped in this fleet; neither can ship again from here.
 */

/**
 * Every `media.*` config key the source actually reads (config(), ModelResolver::for(), binders).
 *
 * Scraped from real string *tokens*, never the raw text: a key named only in a comment or docblock
 * is not a read, and counting it would let this test pass over a key nothing executes.
 *
 * @return list<string>
 */
function mediaConfigKeysRead(): array
{
    $keys = [];

    foreach (mediaSourceFiles() as $file) {
        foreach (token_get_all((string) file_get_contents($file)) as $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $literal = trim($token[1], "'\"");

            // `media.php` is the route/config *filename* declared on the package, not a config key.
            if (preg_match('/^media\.[a-z0-9_.]+$/i', $literal) === 1 && ! str_ends_with($literal, '.php')) {
                $keys[] = $literal;
            }
        }
    }

    sort($keys);

    return array_values(array_unique($keys));
}

/** @return list<string> */
function mediaSourceFiles(): array
{
    $files = [];

    foreach (['/../../src', '/../../routes', '/../../database'] as $directory) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__.$directory));

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

/**
 * Every leaf key in the shipped config file. Descends into associative sections and stops at
 * scalars and list values (`stream.middleware`, `responsive.widths`, …).
 *
 * @param  array<array-key, mixed>  $config
 * @return list<string>
 */
function mediaConfigLeaves(array $config, string $prefix = 'media'): array
{
    $leaves = [];

    foreach ($config as $key => $value) {
        $path = $prefix.'.'.$key;

        if (is_array($value) && $value !== [] && ! array_is_list($value)) {
            $leaves = [...$leaves, ...mediaConfigLeaves($value, $path)];

            continue;
        }

        $leaves[] = $path;
    }

    return $leaves;
}

it('ships every config key the source reads', function (): void {
    $shipped = require __DIR__.'/../../config/media.php';
    $read = mediaConfigKeysRead();

    expect($read)->not->toBeEmpty();

    $missing = array_values(array_filter(
        $read,
        fn (string $key): bool => ! Arr::has(['media' => $shipped], $key),
    ));

    expect($missing)->toBe([]);
});

it('reads every config key it ships', function (): void {
    $shipped = require __DIR__.'/../../config/media.php';
    $read = mediaConfigKeysRead();

    $leaves = mediaConfigLeaves($shipped);

    expect($leaves)->not->toBeEmpty();

    $dead = array_values(array_filter(
        $leaves,
        fn (string $key): bool => ! in_array($key, $read, true),
    ));

    expect($dead)->toBe([]);
});

it('resolves the media model only through the model seam', function (): void {
    $offenders = [];

    foreach (mediaSourceFiles() as $file) {
        if (str_ends_with($file, 'Support/MediaModel.php')) {
            continue;
        }

        if (str_contains((string) file_get_contents($file), 'media.media_model')) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([]);
});
