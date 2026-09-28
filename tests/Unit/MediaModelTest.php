<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

it('uses uuid as its route key', function (): void {
    expect((new Media)->getRouteKeyName())->toBe('uuid');
});

it('uses the configured table name', function (): void {
    expect((new Media)->getTable())->toBe('media');

    config()->set('media.table_name', 'custom_media');
    expect((new Media)->getTable())->toBe('custom_media');
});

it('builds the original path from the uuid', function (): void {
    $media = Media::factory()->create(['uuid' => mediaUuid('abc-uuid'), 'file_name' => 'photo.jpg']);

    expect($media->getPath())->toBe(mediaUuid('abc-uuid').'/photo.jpg');
});

it('reports whether it is an image', function (): void {
    expect(Media::factory()->make(['mime_type' => 'image/png'])->isImage())->toBeTrue()
        ->and(Media::factory()->make(['mime_type' => 'application/pdf'])->isImage())->toBeFalse()
        ->and(Media::factory()->make(['mime_type' => null])->isImage())->toBeFalse();
});

it('reads and writes custom properties', function (): void {
    $media = Media::factory()->create(['custom_properties' => ['alt' => 'cat']]);

    expect($media->getCustomProperty('alt'))->toBe('cat')
        ->and($media->getCustomProperty('missing', 'fallback'))->toBe('fallback');

    $media->setCustomProperty('focus', 'center');
    expect($media->getCustomProperty('focus'))->toBe('center');
});

it('tracks generated variants', function (): void {
    $media = Media::factory()->make(['generated_variants' => [
        'thumb' => ['file_name' => 'thumb.webp', 'format' => 'webp', 'disk' => 'hot'],
        'legacy' => true,
    ]]);

    expect($media->hasGeneratedVariant('thumb'))->toBeTrue()
        ->and($media->hasGeneratedVariant('display'))->toBeFalse()
        ->and($media->hasGeneratedVariant('legacy'))->toBeFalse()
        ->and($media->hasGeneratedVariant(''))->toBeFalse()
        ->and($media->diskFor('thumb'))->toBe('hot')
        ->and($media->getPath('thumb'))->toBe($media->uuid.'/variants/thumb.webp');
});

it('forgets a generated variant record', function (): void {
    $media = Media::factory()->make(['generated_variants' => [
        'thumb' => ['file_name' => 'thumb.webp', 'format' => 'webp', 'disk' => 'hot'],
    ]]);

    $media->forgetGeneratedVariant('thumb');

    expect($media->generated_variants)->toBe([])
        ->and($media->generatedVariants())->toBe([]);
});

it('exposes a public url', function (): void {
    $media = Media::factory()->create(['uuid' => mediaUuid('u1'), 'file_name' => 'a.jpg', 'disk' => 'public']);

    expect($media->getUrl())->toContain(mediaUuid('u1').'/a.jpg');
});

it('reads a stream from storage', function (): void {
    $uuid = mediaUuid('u2');
    Storage::disk('public')->put("{$uuid}/a.txt", 'streamy');
    $media = Media::factory()->create(['uuid' => $uuid, 'file_name' => 'a.txt', 'disk' => 'public']);

    $stream = $media->getStream();
    expect(stream_get_contents($stream))->toBe('streamy');
    fclose($stream);
});

it('scopes global media', function (): void {
    Media::factory()->global()->create();
    Media::factory()->create(['model_type' => 'X', 'model_id' => 1]);

    expect(Media::query()->global()->get())->toHaveCount(1);
});

it('scopes media in a bucket', function (): void {
    Media::factory()->inBucket('a')->create();
    Media::factory()->inBucket('b')->create();

    expect(Media::query()->inBucket('a')->get())->toHaveCount(1);
});

it('scopes media for a model', function (): void {
    $user = TestUser::query()->create(['name' => 'Jane']);
    Media::factory()->create(['model_type' => $user->getMorphClass(), 'model_id' => $user->id]);
    Media::factory()->global()->create();

    expect(Media::query()->forModel($user)->get())->toHaveCount(1);
});

it('scopes ordered media', function (): void {
    Media::factory()->create(['order_column' => 2]);
    Media::factory()->create(['order_column' => 1]);

    expect(Media::query()->ordered()->pluck('order_column')->all())->toBe([1, 2]);
});

it('scopes draft media', function (): void {
    Media::factory()->create(['draft_token' => 'tok']);
    Media::factory()->create(['draft_token' => null]);

    expect(Media::query()->drafts()->get())->toHaveCount(1);
});

it('relates back to its owning model', function (): void {
    $user = TestUser::query()->create(['name' => 'Jane']);
    $media = Media::factory()->create(['model_type' => $user->getMorphClass(), 'model_id' => $user->id]);

    expect($media->model->is($user))->toBeTrue();
});

it('soft deletes without removing files', function (): void {
    $uuid = mediaUuid('s1');
    Storage::disk('public')->put("{$uuid}/a.jpg", 'x');
    $media = Media::factory()->create(['uuid' => $uuid, 'file_name' => 'a.jpg', 'disk' => 'public']);

    $media->delete();

    expect($media->trashed())->toBeTrue();
    Storage::disk('public')->assertExists("{$uuid}/a.jpg");
});
