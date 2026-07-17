<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Models\Media;

it('deletes files on force delete', function (): void {
    $uuid = mediaUuid('o1');
    Storage::disk('public')->put("{$uuid}/a.jpg", 'x');
    $media = Media::factory()->create(['uuid' => $uuid, 'file_name' => 'a.jpg', 'disk' => 'public']);

    $media->forceDelete();

    Storage::disk('public')->assertMissing("{$uuid}/a.jpg");
    Storage::disk('public')->assertMissing($uuid);
});

it('keeps files on soft delete', function (): void {
    $uuid = mediaUuid('o2');
    Storage::disk('public')->put("{$uuid}/a.jpg", 'x');
    $media = Media::factory()->create(['uuid' => $uuid, 'file_name' => 'a.jpg', 'disk' => 'public']);

    $media->delete();

    Storage::disk('public')->assertExists("{$uuid}/a.jpg");
});
