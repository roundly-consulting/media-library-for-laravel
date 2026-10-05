<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Exceptions;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\EdgeCaseUser;

/*
 * Variants render after the media is stored — and, in a single-file bucket, after the previous
 * media is gone. A variant that fails to render there must not fail the call: the caller would see
 * an error for an add that in fact replaced their file. It is reported, and the media stays.
 */

/** A PNG with a valid header (so its size reads fine) and a body no decoder can read. */
function corruptPng(): string
{
    $header = substr((string) file_get_contents(__DIR__.'/../files/wide.png'), 0, 33);
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    $path = sys_get_temp_dir().'/media-corrupt-'.uniqid().'.png';
    file_put_contents($path, $header.$chunk('IDAT', str_repeat("\xAB", 64)).$chunk('IEND', ''));

    return $path;
}

beforeEach(fn () => Exceptions::fake());

it('keeps an add whose variant fails to render, reporting the failure', function (string $driver): void {
    config()->set('media.image_driver', $driver);
    $owner = EdgeCaseUser::query()->create(['name' => 'Ada']);

    $media = MediaLibrary::for($owner)->add(corruptPng())->toBucket('single-thumb');

    expect(Media::query()->find($media->id))->not->toBeNull()
        ->and($media->hasGeneratedVariant('thumb'))->toBeFalse()
        ->and(MediaLibrary::for($owner)->get('single-thumb')->pluck('id')->all())->toBe([$media->id]);

    Exceptions::assertReportedCount(1);
})->with(['gd', 'imagick']);

it('keeps an attach whose variant fails to render, reporting the failure', function (string $driver): void {
    config()->set('media.image_driver', $driver);
    $owner = EdgeCaseUser::query()->create(['name' => 'Ada']);
    $global = MediaLibrary::add(corruptPng())->toBucket('library');

    $attached = MediaLibrary::attach($global, $owner, 'single-thumb');

    expect(Media::query()->find($attached->id))->not->toBeNull()
        ->and($attached->hasGeneratedVariant('thumb'))->toBeFalse();

    Exceptions::assertReportedCount(1);
})->with(['gd', 'imagick']);

it('keeps a replace whose variant fails to render, reporting the failure', function (string $driver): void {
    config()->set('media.image_driver', $driver);
    $owner = EdgeCaseUser::query()->create(['name' => 'Ada']);
    $media = MediaLibrary::for($owner)->add(__DIR__.'/../files/wide.png')->toBucket('single-thumb');

    expect($media->hasGeneratedVariant('thumb'))->toBeTrue();

    MediaLibrary::replace($media, corruptPng());

    $fresh = Media::query()->findOrFail($media->id);

    expect($fresh->size)->toBe($media->size)
        ->and($fresh->hasGeneratedVariant('thumb'))->toBeFalse();

    Exceptions::assertReportedCount(1);
})->with(['gd', 'imagick']);

it('still renders the variants that do work next to one that fails', function (): void {
    config()->set('media.image_driver', 'gd');
    $owner = EdgeCaseUser::query()->create(['name' => 'Ada']);

    // The `broken` variant's disk refuses the write.
    $media = MediaLibrary::for($owner)->add(__DIR__.'/../files/wide.png')->toBucket('half-broken');

    expect($media->hasGeneratedVariant('thumb'))->toBeTrue()
        ->and($media->hasGeneratedVariant('broken'))->toBeFalse();

    Exceptions::assertReportedCount(1);
});
