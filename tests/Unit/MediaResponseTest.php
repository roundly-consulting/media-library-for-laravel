<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Models\Media;

function storedMedia(string $label = 'resp-uuid', string $body = 'file-body', ?string $mime = 'text/plain'): Media
{
    $uuid = mediaUuid($label);

    Storage::disk('public')->put("{$uuid}/file.txt", $body);

    return Media::factory()->create([
        'uuid' => $uuid,
        'file_name' => 'file.txt',
        'disk' => 'public',
        'mime_type' => $mime,
    ]);
}

it('builds an inline streamed response', function (): void {
    $media = storedMedia();

    $response = $media->toResponse(Request::create('/'));

    expect($response->headers->get('content-disposition'))->toContain('inline')
        ->and($response->headers->get('content-type'))->toStartWith('text/plain');

    ob_start();
    $response->sendContent();
    expect(ob_get_clean())->toBe('file-body');
});

it('builds a download response with a custom name', function (): void {
    $media = storedMedia('dl-uuid');

    $response = $media->toDownloadResponse('renamed.txt');

    expect($response->headers->get('content-disposition'))->toContain('attachment')
        ->and($response->headers->get('content-disposition'))->toContain('renamed.txt');
});

it('falls back to the stored file name when no download name is given', function (): void {
    $media = storedMedia('dl2-uuid');

    expect($media->toDownloadResponse()->headers->get('content-disposition'))->toContain('file.txt');
});

it('uses the media mime type for the content-type header', function (): void {
    $media = storedMedia('mime-uuid', mime: 'application/x-custom');

    $response = $media->toResponse(Request::create('/'));

    expect($response->headers->get('content-type'))->toStartWith('application/x-custom');
});

it('falls back to disk mime detection when the media has no mime type', function (): void {
    $media = storedMedia('nomime-uuid', mime: null);

    $response = $media->toResponse(Request::create('/'));

    // Storage::response() auto-detects from the stored file when no Content-Type is supplied.
    expect($response->headers->get('content-type'))->not->toBeNull();
});

it('returns the download response when download=1 is requested', function (): void {
    $media = storedMedia('dlflag-uuid');

    $response = $media->toResponse(Request::create('/?download=1'));

    expect($response->headers->get('content-disposition'))->toContain('attachment');
});

it('serves a suffix byte range', function (): void {
    $media = storedMedia('suffix-uuid', 'abcdefghij');

    $response = $media->toResponse(Request::create('/', server: ['HTTP_RANGE' => 'bytes=-3']));

    expect($response->getStatusCode())->toBe(206)
        ->and($response->headers->get('content-range'))->toBe('bytes 7-9/10');

    ob_start();
    $response->sendContent();
    expect(ob_get_clean())->toBe('hij');
});

it('returns 416 for an unsatisfiable range', function (): void {
    $media = storedMedia('bad-range-uuid', 'abc');

    $response = $media->toResponse(Request::create('/', server: ['HTTP_RANGE' => 'bytes=99-200']));

    expect($response->getStatusCode())->toBe(416)
        ->and($response->headers->get('content-range'))->toBe('bytes */3');
});

it('returns 416 for a malformed range', function (): void {
    $media = storedMedia('malformed-uuid', 'abc');

    $response = $media->toResponse(Request::create('/', server: ['HTTP_RANGE' => 'items=0-1']));

    expect($response->getStatusCode())->toBe(416);
});

it('returns 416 for an empty range', function (): void {
    $media = storedMedia('empty-range-uuid', 'abc');

    $response = $media->toResponse(Request::create('/', server: ['HTTP_RANGE' => 'bytes=-']));

    expect($response->getStatusCode())->toBe(416);
});

it('serves an open-ended range to the end of the file', function (): void {
    $media = storedMedia('open-range-uuid', 'abcdefghij');

    $response = $media->toResponse(Request::create('/', server: ['HTTP_RANGE' => 'bytes=5-']));

    expect($response->getStatusCode())->toBe(206)
        ->and($response->headers->get('content-range'))->toBe('bytes 5-9/10');

    ob_start();
    $response->sendContent();
    expect(ob_get_clean())->toBe('fghij');
});
