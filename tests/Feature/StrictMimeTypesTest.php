<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Support\MediaConfig;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\DropBoxUser;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/*
 * A bucket without an acceptsMimeTypes() list accepts any type — HTML and SVG included. Strict
 * mode (`media.mime_types.strict`, off by default) holds such a bucket, and every global add, to a
 * default list instead.
 */

const STRICT_HTML = '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>';

function dropBoxUser(): DropBoxUser
{
    return DropBoxUser::query()->create(['name' => 'Ada']);
}

it('accepts any type in a bucket without a list while strict mode is off', function (): void {
    $user = dropBoxUser();

    $media = MediaLibrary::for($user)->addFromString(STRICT_HTML)->toBucket('loose');
    $global = MediaLibrary::addFromString(STRICT_HTML)->toBucket('library');

    expect($media->mime_type)->toBe('text/html')
        ->and($global->mime_type)->toBe('text/html')
        ->and(MediaLibrary::rulesFor(DropBoxUser::class, 'loose'))->each->not->toStartWith('mimetypes:');
});

it('holds a bucket without a list, and a global add, to the default list in strict mode', function (): void {
    config()->set('media.mime_types.strict', true);
    $user = dropBoxUser();

    expect(fn () => MediaLibrary::for($user)->addFromString(STRICT_HTML)->toBucket('loose'))
        ->toThrow(FileUnacceptableForBucket::class, 'text/html')
        ->and(fn () => MediaLibrary::addFromString(STRICT_HTML)->toBucket('library'))
        ->toThrow(FileUnacceptableForBucket::class, 'text/html')
        ->and(fn () => MediaLibrary::for($user)->add(__DIR__.'/../files/pixel.png')->toBucket('undeclared-bucket'))
        ->not->toThrow(FileUnacceptableForBucket::class);

    expect(MediaLibrary::for($user)->add(__DIR__.'/../files/pixel.png')->toBucket('loose')->mime_type)->toBe('image/png')
        ->and(MediaLibrary::add(__DIR__.'/../files/note.txt')->toBucket('library')->mime_type)->toBe('text/plain');
});

it('derives the default list into the validation rules in strict mode', function (): void {
    config()->set('media.mime_types.strict', true);

    expect(MediaLibrary::rulesFor(DropBoxUser::class, 'loose'))->toContain('mimetypes:'.implode(',', MediaConfig::DEFAULT_MIME_TYPES))
        ->and(MediaLibrary::rulesFor(DropBoxUser::class, 'pictures'))->toContain('mimetypes:image/png')
        ->and(MediaLibrary::rulesFor(DropBoxUser::class, 'drop'))->each->not->toStartWith('mimetypes:');
});

it('keeps a declared list and an explicit opt-out as they are in strict mode', function (): void {
    config()->set('media.mime_types.strict', true);
    $user = dropBoxUser();

    expect(MediaLibrary::for($user)->addFromString(STRICT_HTML)->toBucket('drop')->mime_type)->toBe('text/html')
        ->and(fn () => MediaLibrary::for($user)->add(__DIR__.'/../files/note.txt')->toBucket('pictures'))
        ->toThrow(FileUnacceptableForBucket::class);

    $bucket = $user->resolveMediaBucket('drop');

    expect($bucket?->isAcceptingAnyMimeType())->toBeTrue()
        ->and($bucket?->accepts('text/html'))->toBeTrue()
        ->and($user->resolveMediaBucket('loose')?->accepts('text/html'))->toBeFalse()
        ->and($user->resolveMediaBucket('loose')?->accepts(null))->toBeFalse()
        ->and($bucket?->acceptsAnyMimeType(false)->accepts('text/html'))->toBeFalse();
});

it('applies the rule when existing media enters a bucket', function (): void {
    $html = MediaLibrary::addFromString(STRICT_HTML)->toBucket('library');

    config()->set('media.mime_types.strict', true);

    expect(fn () => MediaLibrary::attach($html, dropBoxUser(), 'loose'))->toThrow(FileUnacceptableForBucket::class)
        ->and(MediaLibrary::attach($html, dropBoxUser(), 'drop')->bucket_name)->toBe('drop');
});

it('takes a default list of the host, lower-cased', function (): void {
    config()->set('media.mime_types.strict', true);
    config()->set('media.mime_types.default', [' Text/Plain ', 'text/plain']);

    expect(MediaConfig::defaultMimeTypes())->toBe(['text/plain'])
        ->and(MediaLibrary::add(__DIR__.'/../files/note.txt')->toBucket('library')->mime_type)->toBe('text/plain')
        ->and(fn () => MediaLibrary::add(__DIR__.'/../files/pixel.png')->toBucket('library'))->toThrow(FileUnacceptableForBucket::class);
});

it('reads a blank default list as the built-in one', function (): void {
    config()->set('media.mime_types.default', '');

    expect(MediaConfig::defaultMimeTypes())->toBe(MediaConfig::DEFAULT_MIME_TYPES)
        ->and(MediaConfig::DEFAULT_MIME_TYPES)->not->toContain('image/svg+xml', 'text/html', 'text/xml', 'application/xml');
});

it('refuses an unusable default list', function (mixed $value): void {
    config()->set('media.mime_types.default', $value);

    expect(static fn () => MediaConfig::defaultMimeTypes())->toThrow(InvalidConfigurationException::class, 'media.mime_types.default');
})->with([
    'empty' => [[]],
    'not a list' => ['image/png'],
    'a junk entry' => [['image/png', 5]],
]);

it('refuses a strict switch that is not a boolean', function (): void {
    config()->set('media.mime_types.strict', 'sometimes');

    expect(static fn () => MediaConfig::strictMimeTypes())->toThrow(InvalidConfigurationException::class, 'media.mime_types.strict');
});

it('reports strict mode in about', function (): void {
    config()->set('media.mime_types.strict', true);

    Artisan::call('about', ['--only' => 'media']);

    expect(Artisan::output())->toContain('Strict mime types')->toContain(count(MediaConfig::DEFAULT_MIME_TYPES).' type(s)');
});
