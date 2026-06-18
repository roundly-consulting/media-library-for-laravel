<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Models\Media;

it('deletes files on force delete', function (): void {
    Storage::disk('public')->put('o1/a.jpg', 'x');
    $media = Media::factory()->create(['uuid' => 'o1', 'file_name' => 'a.jpg', 'disk' => 'public']);

    $media->forceDelete();

    Storage::disk('public')->assertMissing('o1/a.jpg');
    Storage::disk('public')->assertMissing('o1');
});

it('keeps files on soft delete', function (): void {
    Storage::disk('public')->put('o2/a.jpg', 'x');
    $media = Media::factory()->create(['uuid' => 'o2', 'file_name' => 'a.jpg', 'disk' => 'public']);

    $media->delete();

    Storage::disk('public')->assertExists('o2/a.jpg');
});
