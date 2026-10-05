<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use RoundlyConsulting\MediaLibrary\Buckets\BucketGuard;
use RoundlyConsulting\MediaLibrary\Buckets\BucketValidationRules;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Support\ExifOrientation;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\EdgeCaseUser;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\OrientedJpeg;

/*
 * `rulesFor()` is what a FormRequest validates with; `BucketGuard` is what the add enforces. Where
 * they disagreed, a file passed validation and then failed the add: the size rule was rounded up
 * to whole kilobytes, and Laravel's `dimensions` reads the stored pixel size (not the EXIF-turned
 * one a viewer sees) and waves every SVG through.
 */

/** Whether the bucket's guard refuses the file at `$path`. */
function guardRefuses(string $bucket, string $path): bool
{
    $guard = app(BucketGuard::class);
    $mimeType = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
    $dimensions = str_starts_with($mimeType, 'image/') ? ExifOrientation::displayDimensions($path) : null;

    try {
        $guard->ensureAccepts($guard->bucketFor(new EdgeCaseUser, $bucket), $bucket, $mimeType, (int) filesize($path), $dimensions[0] ?? null, $dimensions[1] ?? null);
    } catch (FileUnacceptableForBucket) {
        return true;
    }

    return false;
}

/** Whether the derived rules refuse the file at `$path`, uploaded as `$name`. */
function rulesRefuse(string $bucket, string $path, string $name): bool
{
    $upload = new UploadedFile($path, $name, null, null, true);

    return Validator::make(['file' => $upload], ['file' => MediaLibrary::rulesFor(EdgeCaseUser::class, $bucket)])->fails();
}

/** A temp file holding `$contents`. */
function agreementFile(string $contents): string
{
    $path = sys_get_temp_dir().'/media-agree-'.uniqid();
    file_put_contents($path, $contents);

    return $path;
}

it('validates exactly what the guard accepts', function (string $bucket, Closure $file, string $name, bool $refused): void {
    $path = $file();

    expect(guardRefuses($bucket, $path))->toBe($refused)
        ->and(rulesRefuse($bucket, $path, $name))->toBe($refused);
})->with([
    'a size just over a non-whole-kilobyte cap' => ['tiny', fn () => agreementFile(str_repeat('a', 1020)), 'a.txt', true],
    'a size exactly at the cap' => ['tiny', fn () => agreementFile(str_repeat('a', 1000)), 'a.txt', false],
    'a photo turned on its side by EXIF' => ['portrait', fn () => OrientedJpeg::path(6), 'turned.jpg', true],
    'a photo displayed as stored' => ['portrait', fn () => OrientedJpeg::path(1), 'wide.jpg', true],
    'a photo within the box' => ['portrait', fn () => __DIR__.'/../files/landscape.jpg', 'small.jpg', false],
    'an svg, whose size cannot be read' => ['portrait', fn () => agreementFile('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"/>'), 'a.svg', true],
    'a file that is no image at all' => ['portrait', fn () => __DIR__.'/../files/note.txt', 'note.txt', false],
]);

it('keeps the size rule exact in kilobytes, and whole numbers whole', function (): void {
    expect(MediaLibrary::rulesFor(EdgeCaseUser::class, 'tiny'))->toContain('max:0.9765625')
        ->and(MediaLibrary::rulesFor(EdgeCaseUser::class, 'portrait'))->toContain('max:262144');
});

it('explains a dimensions failure with the application\'s own dimensions message', function (): void {
    $validator = Validator::make(
        ['photo' => new UploadedFile(OrientedJpeg::path(6), 'turned.jpg', null, null, true)],
        ['photo' => MediaLibrary::rulesFor(EdgeCaseUser::class, 'portrait')],
    );

    expect($validator->errors()->first('photo'))->toBe('The photo field has invalid image dimensions.');
});

it('uses an application line of its own for the dimensions rule when there is one', function (): void {
    app('translator')->addLines(['validation.media_dimensions' => 'The :attribute is the wrong shape.'], 'en');

    $validator = Validator::make(
        ['photo' => new UploadedFile(OrientedJpeg::path(6), 'turned.jpg', null, null, true)],
        ['photo' => MediaLibrary::rulesFor(EdgeCaseUser::class, 'portrait')],
    );

    expect($validator->errors()->first('photo'))->toBe('The photo is the wrong shape.');
});

it('fails the dimensions rule for anything that is not a file', function (): void {
    expect(BucketValidationRules::passesDimensions('not a file', ['max_width=10']))->toBeFalse()
        ->and(Validator::make(['photo' => 'text'], ['photo' => 'media_dimensions:max_width=10'])->fails())->toBeTrue();
});
