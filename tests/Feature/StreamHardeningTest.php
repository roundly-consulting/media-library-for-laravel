<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

function storedOnSecure(string $label, string $fileName, string $mime, string $body): Media
{
    $uuid = mediaUuid($label);

    Storage::disk('secure')->put("{$uuid}/{$fileName}", $body);

    return Media::factory()->create([
        'uuid' => $uuid,
        'file_name' => $fileName,
        'disk' => 'secure',
        'visibility' => 'private',
        'mime_type' => $mime,
    ]);
}

function signedStreamUrl(Media $media, string $variant = ''): string
{
    return URL::temporarySignedRoute('media.stream', now()->addMinutes(5), [
        'media' => $media->uuid,
        'variant' => $variant,
    ]);
}

it('serves active content as a sandboxed attachment', function (): void {
    $media = storedOnSecure('evil-html', 'evil.html', 'text/html', '<html><script>alert(document.cookie)</script></html>');

    $response = $this->get(signedStreamUrl($media));

    $response->assertOk();
    expect($response->headers->get('content-disposition'))->toStartWith('attachment')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff')
        ->and($response->headers->get('content-security-policy'))->toContain('sandbox');
});

it('serves svg as an attachment too', function (): void {
    $media = storedOnSecure('evil-svg', 'x.svg', 'image/svg+xml', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"/>');

    $response = $this->get(signedStreamUrl($media));

    expect($response->headers->get('content-disposition'))->toStartWith('attachment');
});

it('keeps serving safe types inline, with nosniff', function (): void {
    $media = storedOnSecure('safe-png', 'pixel.png', 'image/png', (string) file_get_contents(__DIR__.'/../files/pixel.png'));

    $response = $this->get(signedStreamUrl($media));

    expect($response->headers->get('content-disposition'))->toStartWith('inline')
        ->and($response->headers->get('x-content-type-options'))->toBe('nosniff');
});

it('ignores an unparseable range and serves the whole file', function (string $range): void {
    $media = storedOnSecure('range-'.md5($range), 'file.txt', 'text/plain', 'streamed-body');

    $response = $this->withHeader('Range', $range)->get(signedStreamUrl($media));

    $response->assertOk();
    expect($response->streamedContent())->toBe('streamed-body');
})->with(['bytes=abc', 'bytes=0-1,4-5', 'bytes=5-2', 'items=0-1']);

it('answers an unsatisfiable range with a sendable 416', function (): void {
    $media = storedOnSecure('range-416', 'file.txt', 'text/plain', 'streamed-body');

    $response = $media->toResponse(Request::create('/', 'GET', server: ['HTTP_RANGE' => 'bytes=100-']));

    expect($response->getStatusCode())->toBe(416)
        ->and($response->headers->get('content-range'))->toBe('bytes */13');

    ob_start();
    $response->sendContent();
    expect(ob_get_clean())->toBe('');
});

it('labels a variant with the content type of its own format', function (): void {
    Bus::fake();
    $media = MediaLibrary::for(TestUser::query()->create(['name' => 'Jane']))
        ->add(__DIR__.'/../files/wide.png')
        ->toBucket('photos');

    $response = $this->get(signedStreamUrl($media, 'thumb'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toBe('image/webp');
});

it('adds nosniff to downloads', function (): void {
    $media = storedOnSecure('download', 'file.txt', 'text/plain', 'streamed-body');

    expect($media->toDownloadResponse()->headers->get('x-content-type-options'))->toBe('nosniff');
});
