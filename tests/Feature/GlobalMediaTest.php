<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Facades\Media;

it('stores a global asset with no owning model', function (): void {
    $media = Media::add(UploadedFile::fake()->image('logo.png'))
        ->usingName('Primary logo')
        ->toBucket('brand', 'hot');

    expect($media->model_type)->toBeNull()
        ->and($media->model_id)->toBeNull()
        ->and($media->bucket_name)->toBe('brand')
        ->and($media->disk)->toBe('hot');

    Storage::disk('hot')->assertExists($media->getPath());
});

it('stores global media from a url', function (): void {
    Http::fake(['*' => Http::response('bytes', 200, ['Content-Type' => 'image/png'])]);

    $media = Media::addFromUrl('https://example.com/poster.png')->toBucket('marketing');

    expect($media->model_type)->toBeNull();
    Storage::disk('public')->assertExists($media->getPath());
});

it('reads global media in a bucket', function (): void {
    Media::add(UploadedFile::fake()->image('a.png'))->toBucket('brand');
    Media::add(UploadedFile::fake()->image('b.png'))->toBucket('brand');
    Media::add(UploadedFile::fake()->image('c.png'))->toBucket('other');

    expect(Media::bucket('brand')->get())->toHaveCount(2);
});

it('finds global media by uuid', function (): void {
    $media = Media::add(UploadedFile::fake()->image('a.png'))->toBucket('brand');

    expect(Media::find($media->uuid)->id)->toBe($media->id)
        ->and(Media::find('00000000-0000-0000-0000-000000000000'))->toBeNull();
});

it('clears a global bucket', function (): void {
    $media = Media::add(UploadedFile::fake()->image('a.png'))->toBucket('brand');
    $path = $media->getPath();

    Media::clearBucket('brand');

    expect(Media::bucket('brand')->get())->toHaveCount(0);
    Storage::disk('public')->assertMissing($path);
});

it('stores global media from a string and base64 and stream', function (): void {
    $a = Media::addFromString('raw')->usingFileName('a.txt')->toBucket('docs');
    $b = Media::addFromBase64(base64_encode('b64'))->usingFileName('b.txt')->toBucket('docs');

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, 'stream');
    rewind($stream);
    $c = Media::addFromStream($stream)->usingFileName('c.txt')->toBucket('docs');
    fclose($stream);

    expect(Media::bucket('docs')->get())->toHaveCount(3)
        ->and(Storage::disk('public')->get($a->getPath()))->toBe('raw')
        ->and(Storage::disk('public')->get($b->getPath()))->toBe('b64')
        ->and(Storage::disk('public')->get($c->getPath()))->toBe('stream');
});

it('stores global media from a disk file', function (): void {
    Storage::disk('public')->put('seed/logo.svg', '<svg/>');

    $media = Media::addFromDisk('seed/logo.svg')->toBucket('brand');

    expect($media->file_name)->toBe('logo.svg');
    Storage::disk('public')->assertExists($media->getPath());
});
