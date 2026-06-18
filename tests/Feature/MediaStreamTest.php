<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RoundlyConsulting\MediaLibrary\Models\Media;

function privateMediaOnDisk(string $disk = 'secure', string $uuid = 'stream-uuid'): Media
{
    Storage::disk($disk)->put("{$uuid}/file.txt", 'streamed-body');

    return Media::factory()->create([
        'uuid' => $uuid,
        'file_name' => 'file.txt',
        'disk' => $disk,
        'visibility' => 'private',
        'mime_type' => 'text/plain',
    ]);
}

it('streams the file for a valid signature', function (): void {
    $media = privateMediaOnDisk();

    $url = $media->getTemporaryUrl(CarbonImmutable::now()->addMinutes(5));

    $response = $this->get($url);

    $response->assertOk();
    expect($response->streamedContent())->toBe('streamed-body');
    expect($response->headers->get('content-type'))->toStartWith('text/plain');
});

it('rejects a request with no signature', function (): void {
    $media = privateMediaOnDisk(uuid: 'nosig-uuid');

    $this->get("/media/{$media->uuid}")->assertForbidden();
});

it('rejects a tampered signature', function (): void {
    $media = privateMediaOnDisk(uuid: 'tamper-uuid');

    $url = $media->getTemporaryUrl(CarbonImmutable::now()->addMinutes(5));

    $this->get($url.'&download=1')->assertForbidden();
});

it('rejects an expired signature', function (): void {
    $media = privateMediaOnDisk(uuid: 'expired-uuid');

    $url = $media->getTemporaryUrl(CarbonImmutable::now()->addMinutes(5));

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(10));

    $this->get($url)->assertForbidden();

    CarbonImmutable::setTestNow();
});

it('serves an inline response by default and an attachment for download=1', function (): void {
    $media = privateMediaOnDisk(uuid: 'disp-uuid');

    $inline = $this->get($media->getTemporaryUrl(CarbonImmutable::now()->addMinutes(5)));
    $inline->assertOk();
    expect($inline->headers->get('content-disposition'))->toContain('inline');

    // The download flag must be inside the signature, so mint a signed URL that includes it.
    $signed = URL::temporarySignedRoute(
        'media.stream',
        CarbonImmutable::now()->addMinutes(5),
        ['media' => $media->uuid, 'download' => 1],
    );

    $download = $this->get($signed);
    $download->assertOk();
    expect($download->headers->get('content-disposition'))->toContain('attachment');
});

it('honours range requests', function (): void {
    $media = privateMediaOnDisk(uuid: 'range-uuid');

    $url = $media->getTemporaryUrl(CarbonImmutable::now()->addMinutes(5));

    $response = $this->get($url, ['Range' => 'bytes=0-4']);

    $response->assertStatus(206);
    expect($response->streamedContent())->toBe('strea');
});

it('returns 404 when the underlying file is missing', function (): void {
    $media = Media::factory()->create([
        'uuid' => 'gone-uuid', 'file_name' => 'file.txt', 'disk' => 'secure', 'visibility' => 'private',
    ]);

    $this->get($media->getTemporaryUrl(CarbonImmutable::now()->addMinutes(5)))->assertNotFound();
});

it('returns 404 for a signed but un-generated variant', function (): void {
    $media = privateMediaOnDisk(uuid: 'novariant-uuid');

    // Mint a signed URL for a variant that was never generated (the generator would refuse to,
    // so we sign the route directly) and assert the controller refuses to serve it.
    $url = URL::temporarySignedRoute(
        'media.stream', CarbonImmutable::now()->addMinutes(5),
        ['media' => $media->uuid, 'variant' => 'thumb'],
    );

    $this->get($url)->assertNotFound();
});

it('streams a public media through the route too', function (): void {
    Storage::disk('secure')->put('pub-uuid/file.txt', 'open-body');
    $media = Media::factory()->create([
        'uuid' => 'pub-uuid', 'file_name' => 'file.txt', 'disk' => 'secure', 'visibility' => 'public',
        'mime_type' => 'text/plain',
    ]);

    $signed = URL::temporarySignedRoute(
        'media.stream', CarbonImmutable::now()->addMinutes(5), ['media' => $media->uuid],
    );

    $response = $this->get($signed);
    $response->assertOk();
    expect($response->streamedContent())->toBe('open-body');
});
