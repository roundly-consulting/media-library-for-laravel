<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Support\FileNames;

it('reduces a name to one safe path segment', function (string $given, string $expected): void {
    expect(FileNames::sanitize($given))->toBe($expected);
})->with([
    'traversal' => ['../../etc/passwd', 'passwd'],
    'windows traversal' => ['..\\..\\boot.ini', 'boot.ini'],
    'hidden file' => ['.htaccess', 'htaccess'],
    'reserved characters' => ['a:b*c?"d<e>f|g#h i%j.txt', 'a-b-c--d-e-f-g-h-i-j.txt'],
    'nothing left' => ['../..', 'file'],
    'empty' => ['', 'file'],
]);

it('truncates an over-long name but keeps its extension', function (): void {
    $name = FileNames::sanitize(str_repeat('a', 400).'.jpeg');

    expect(strlen($name))->toBe(200)
        ->and($name)->toEndWith('.jpeg');
});

it('truncates an over-long extension-less name', function (): void {
    expect(strlen(FileNames::sanitize(str_repeat('b', 400))))->toBe(200);
});

it('makes a stored extension tell the truth where it matters', function (string $name, ?string $mime, string $expected): void {
    expect(FileNames::conform($name, $mime))->toBe($expected);
})->with([
    'truthful, any case' => ['IMG_0001.JPG', 'image/jpeg', 'IMG_0001.JPG'],
    'harmless mismatch kept' => ['data.csv', 'text/plain', 'data.csv'],
    'html polyglot' => ['x.html', 'image/png', 'x.png'],
    'svg polyglot' => ['x.svg', 'image/png', 'x.png'],
    'real html kept' => ['page.html', 'text/html', 'page.html'],
    'html that is text' => ['page.html', 'text/plain', 'page.txt'],
    'php source' => ['shell.php', 'text/x-php', 'shell.txt'],
    'php with image bytes' => ['shell.php', 'image/png', 'shell.png'],
    'php binary' => ['shell.phtml', 'application/x-unknown-thing', 'shell.bin'],
    'unknown mime, dangerous ext' => ['x.js', null, 'x.bin'],
    'inner php' => ['shell.php.png', 'image/png', 'shell-php.png'],
    'inner html' => ['a.html.jpg', 'image/jpeg', 'a-html.jpg'],
    'harmless inner dot' => ['report.v2.pdf', 'application/pdf', 'report.v2.pdf'],
    'no extension, known type' => ['upload', 'image/webp', 'upload.webp'],
    'no extension, unknown type' => ['upload', 'application/x-unknown-thing', 'upload'],
    'no extension, no type' => ['upload', null, 'upload'],
]);

it('reads the lower-cased extension of a stored name', function (): void {
    expect(FileNames::extensionOf('IMG.JPG'))->toBe('jpg')
        ->and(FileNames::extensionOf('README'))->toBeNull();
});
