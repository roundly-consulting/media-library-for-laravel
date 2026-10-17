<?php

declare(strict_types=1);

use Illuminate\Support\Arr;

/*
 * English and Slovak ship together (fleet rule): the same keys, and the same placeholders per key,
 * in every language file.
 */

dataset('translation files', ['validation']);

/** @return array<string, string> */
function translationLines(string $locale, string $file): array
{
    return Arr::dot(require __DIR__."/../../resources/lang/{$locale}/{$file}.php");
}

it('ships the same keys in english and slovak', function (string $file): void {
    $en = translationLines('en', $file);
    $sk = translationLines('sk', $file);

    expect($en)->not->toBeEmpty()
        ->and(array_keys($sk))->toBe(array_keys($en));
})->with('translation files');

it('keeps the placeholders of every line', function (string $file): void {
    $placeholders = static function (string $line): array {
        preg_match_all('/:[a-z_]+/', $line, $matches);
        $found = array_unique($matches[0]);
        sort($found);

        return $found;
    };

    $sk = translationLines('sk', $file);

    foreach (translationLines('en', $file) as $key => $line) {
        expect($placeholders($sk[$key]))->toBe($placeholders($line), "placeholders of {$key}");
    }
})->with('translation files');

it('translates through the package namespace', function (): void {
    expect(trans('media::validation.remote_url.port', ['attribute' => 'url']))->toBe('The url field uses a port that is not allowed.');

    app()->setLocale('sk');

    expect(trans('media::validation.remote_url.port', ['attribute' => 'url']))->toBe('Pole url používa nepovolený port.');
});

it('has a line for every verdict the guard can give', function (): void {
    expect(array_keys((require __DIR__.'/../../resources/lang/en/validation.php')['remote_url']))->toBe(['invalid_url', 'scheme', 'credentials', 'non_canonical_host', 'blocked', 'port', 'unresolved']);
});
