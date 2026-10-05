<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Models\Media;

/*
 * `name` is a 255-character column. A longer client file name reached the insert untouched: on
 * Postgres the insert fails (22001) after the file was already written, leaving it orphaned.
 */

it('caps the display name derived from a long client file name at 255 characters', function (): void {
    $upload = UploadedFile::fake()->createWithContent(str_repeat('é', 300).'.txt', 'hello');

    $media = MediaLibrary::add($upload)->toBucket('library');

    expect(mb_strlen($media->name))->toBe(255)
        ->and(mb_strlen((string) Media::query()->findOrFail($media->id)->name))->toBe(255);
});

it('caps a usingName() display name at 255 characters', function (): void {
    $media = MediaLibrary::add(__DIR__.'/../files/note.txt')->usingName(str_repeat('n', 300))->toBucket('library');

    expect(strlen($media->name))->toBe(255);
});

it('leaves no file behind when saving the row fails', function (): void {
    Media::creating(static function (): void {
        throw new RuntimeException('value too long for type character varying(255)');
    });

    expect(fn () => MediaLibrary::add(__DIR__.'/../files/note.txt')->toBucket('library'))
        ->toThrow(RuntimeException::class, 'character varying');

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('keeps the shared file when saving a deduplicated row fails', function (): void {
    $first = MediaLibrary::add(__DIR__.'/../files/note.txt')->toBucket('library');

    Media::creating(static function (): void {
        throw new RuntimeException('the insert failed');
    });

    expect(fn () => MediaLibrary::add(__DIR__.'/../files/note.txt')->toBucket('library'))->toThrow(RuntimeException::class);

    Storage::disk('public')->assertExists($first->getPath());
});
