<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Exceptions\InvalidVariant;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\DiskResolver;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

function userForUrls(): TestUser
{
    return TestUser::query()->create(['name' => 'Jane']);
}

it('builds a variant path under the uuid variants dir', function (): void {
    $user = userForUrls();
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    expect($media->getPath('small'))->toBe($media->uuid.'/variants/small.jpg');
});

it('uses the original extension for a format-less variant', function (): void {
    $user = userForUrls();
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    // 'keepformat' has no ->format(), so it inherits the original png extension.
    expect($media->getPath('keepformat'))->toBe($media->uuid.'/variants/keepformat.png');
});

it('falls back to jpg when the original has no extension', function (): void {
    $media = new Media([
        'uuid' => 'abc',
        'extension' => null,
        'disk' => 'public',
    ]);

    expect($media->getPath('whatever'))->toBe('abc/variants/whatever.jpg');
});

it('normalizes a jpeg original extension to jpg for variants', function (): void {
    $media = new Media([
        'uuid' => 'abc',
        'extension' => 'jpeg',
        'disk' => 'public',
    ]);

    expect($media->getPath('whatever'))->toBe('abc/variants/whatever.jpg');
});

it('resolves a public url for a generated variant', function (): void {
    $user = userForUrls();
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    $expected = Storage::disk('public')->url($media->getPath('small'));

    expect($media->getUrl('small'))->toBe($expected);
});

it('throws for an un-generated variant by default', function (): void {
    $user = userForUrls();
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    expect(fn () => $media->getUrl('missing'))->toThrow(InvalidVariant::class);
});

it('falls back to the original url when configured', function (): void {
    config()->set('media.url_fallback_to_original', true);

    $user = userForUrls();
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    expect($media->getUrl('missing'))->toBe($media->getUrl());
});

it('reports the disk a variant lives on', function (): void {
    $user = userForUrls();
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('photos');

    expect($media->diskFor('thumb'))->toBe('hot')
        ->and($media->diskFor())->toBe('public');
});

it('reads a variant stream from its disk', function (): void {
    $user = userForUrls();
    $media = $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('covers');

    $stream = $media->getStream('small');

    expect(is_resource($stream))->toBeTrue();

    if (is_resource($stream)) {
        fclose($stream);
    }
});

it('resolves the variant disk by precedence', function (): void {
    $resolver = new DiskResolver;

    expect($resolver->resolveVariantDisk('hot', 'cold', 'public'))->toBe('hot')
        ->and($resolver->resolveVariantDisk(null, 'cold', 'public'))->toBe('cold')
        ->and($resolver->resolveVariantDisk(null, null, 'public'))->toBe('public');
});
