<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;

it('stores a global asset with no owning model', function (): void {
    $media = MediaLibrary::add(UploadedFile::fake()->image('logo.png'))
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

    $media = MediaLibrary::addFromUrl('https://example.com/poster.png')->toBucket('marketing');

    expect($media->model_type)->toBeNull();
    Storage::disk('public')->assertExists($media->getPath());
});

it('reads global media in a bucket', function (): void {
    MediaLibrary::add(UploadedFile::fake()->image('a.png'))->toBucket('brand');
    MediaLibrary::add(UploadedFile::fake()->image('b.png'))->toBucket('brand');
    MediaLibrary::add(UploadedFile::fake()->image('c.png'))->toBucket('other');

    expect(MediaLibrary::bucket('brand')->get())->toHaveCount(2);
});

it('finds global media by uuid', function (): void {
    $media = MediaLibrary::add(UploadedFile::fake()->image('a.png'))->toBucket('brand');

    expect(MediaLibrary::find($media->uuid)->id)->toBe($media->id)
        ->and(MediaLibrary::find('00000000-0000-0000-0000-000000000000'))->toBeNull();
});

it('clears a global bucket', function (): void {
    $media = MediaLibrary::add(UploadedFile::fake()->image('a.png'))->toBucket('brand');
    $path = $media->getPath();

    MediaLibrary::clearBucket('brand');

    expect(MediaLibrary::bucket('brand')->get())->toHaveCount(0);
    Storage::disk('public')->assertMissing($path);
});

it('stores global media from a string and base64 and stream', function (): void {
    $a = MediaLibrary::addFromString('raw')->usingFileName('a.txt')->toBucket('docs');
    $b = MediaLibrary::addFromBase64(base64_encode('b64'))->usingFileName('b.txt')->toBucket('docs');

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, 'stream');
    rewind($stream);
    $c = MediaLibrary::addFromStream($stream)->usingFileName('c.txt')->toBucket('docs');
    fclose($stream);

    expect(MediaLibrary::bucket('docs')->get())->toHaveCount(3)
        ->and(Storage::disk('public')->get($a->getPath()))->toBe('raw')
        ->and(Storage::disk('public')->get($b->getPath()))->toBe('b64')
        ->and(Storage::disk('public')->get($c->getPath()))->toBe('stream');
});

it('stores global media from a disk file', function (): void {
    Storage::disk('public')->put('seed/logo.svg', '<svg/>');

    $media = MediaLibrary::addFromDisk('seed/logo.svg')->toBucket('brand');

    expect($media->file_name)->toBe('logo.svg');
    Storage::disk('public')->assertExists($media->getPath());
});
