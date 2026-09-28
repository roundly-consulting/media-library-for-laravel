<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use RoundlyConsulting\MediaLibrary\Exceptions\InvalidVariant;
use RoundlyConsulting\MediaLibrary\Exceptions\MediaCannotBeStreamed;
use RoundlyConsulting\MediaLibrary\Exceptions\TemporaryUrlNotSupported;
use RoundlyConsulting\MediaLibrary\Models\Media;

it('exposes a public url for public media', function (): void {
    $media = Media::factory()->create([
        'uuid' => mediaUuid('u1'), 'file_name' => 'a.jpg', 'disk' => 'public', 'visibility' => 'public',
    ]);

    expect($media->getUrl())->toContain(mediaUuid('u1').'/a.jpg');
});

it('throws when asking a private media for its public url', function (): void {
    $media = Media::factory()->create([
        'uuid' => mediaUuid('p1'), 'file_name' => 'a.jpg', 'disk' => 'secure', 'visibility' => 'private',
    ]);

    $media->getUrl();
})->throws(MediaCannotBeStreamed::class);

it('reports its visibility', function (): void {
    expect(Media::factory()->make(['visibility' => 'public'])->isPublic())->toBeTrue()
        ->and(Media::factory()->make(['visibility' => 'private'])->isPrivate())->toBeTrue()
        ->and(Media::factory()->make(['visibility' => 'private'])->isPublic())->toBeFalse();
});

it('returns a native presigned temporary url when the disk supports it', function (): void {
    $this->makeDiskPresignCapable('s3');

    $media = Media::factory()->create([
        'uuid' => mediaUuid('s3media'), 'file_name' => 'a.jpg', 'disk' => 's3', 'visibility' => 'private',
    ]);

    $url = $media->getTemporaryUrl(CarbonImmutable::now()->addMinutes(5));

    expect($url)->toContain('s3.example.com')
        ->and($url)->toContain(mediaUuid('s3media').'/a.jpg')
        ->and($url)->toContain('expires=');
});

it('falls back to a signed route url for local private media', function (): void {
    $media = Media::factory()->create([
        'uuid' => mediaUuid('localmedia'), 'file_name' => 'a.jpg', 'disk' => 'secure', 'visibility' => 'private',
    ]);

    $url = $media->getTemporaryUrl(CarbonImmutable::now()->addMinutes(5));

    expect($url)->toContain('/media/'.mediaUuid('localmedia'))
        ->and($url)->toContain('signature=');

    $request = Request::create($url);
    expect(URL::hasValidSignature($request))->toBeTrue();
});

it('signs the requested variant into the temporary url', function (): void {
    $media = Media::factory()->create([
        'uuid' => mediaUuid('varmedia'), 'file_name' => 'a.jpg', 'disk' => 'secure', 'visibility' => 'private',
        'generated_variants' => ['thumb' => ['file_name' => 'thumb.jpg', 'format' => 'jpg', 'disk' => 'secure']],
    ]);

    $url = $media->getTemporaryUrl(CarbonImmutable::now()->addMinutes(5), 'thumb');

    expect($url)->toContain('/media/'.mediaUuid('varmedia').'/thumb');
});

it('throws when neither presign nor the signed route is available', function (): void {
    config()->set('media.stream.enabled', false);

    $media = Media::factory()->create([
        'uuid' => mediaUuid('nostream'), 'file_name' => 'a.jpg', 'disk' => 'secure', 'visibility' => 'private',
    ]);

    $media->getTemporaryUrl(CarbonImmutable::now()->addMinutes(5));
})->throws(TemporaryUrlNotSupported::class);

it('throws for an un-generated variant url by default', function (): void {
    $media = Media::factory()->create([
        'uuid' => mediaUuid('ung'), 'file_name' => 'a.jpg', 'disk' => 'public', 'visibility' => 'public',
    ]);

    $media->getUrl('thumb');
})->throws(InvalidVariant::class);

it('falls back to the original url for an un-generated variant when configured', function (): void {
    config()->set('media.url_fallback_to_original', true);

    $media = Media::factory()->create([
        'uuid' => mediaUuid('ung2'), 'file_name' => 'a.jpg', 'disk' => 'public', 'visibility' => 'public',
    ]);

    expect($media->getUrl('thumb'))->toContain(mediaUuid('ung2').'/a.jpg');
});

it('falls back to the original temporary url for an un-generated variant when configured', function (): void {
    config()->set('media.url_fallback_to_original', true);

    $media = Media::factory()->create([
        'uuid' => mediaUuid('ung3'), 'file_name' => 'a.jpg', 'disk' => 'secure', 'visibility' => 'private',
    ]);

    $url = $media->getTemporaryUrl(CarbonImmutable::now()->addMinutes(5), 'thumb');

    expect($url)->toContain('/media/'.mediaUuid('ung3'))
        ->and($url)->not->toContain('/thumb');
});
