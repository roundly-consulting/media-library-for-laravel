<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Contracts\PathGenerator;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\Checksum;
use RoundlyConsulting\MediaLibrary\Support\StoredFiles;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

it('pins the derived path of a legacy row before another row points at it', function (): void {
    $uuid = mediaUuid('legacy-source');
    Storage::disk('public')->put("{$uuid}/logo.png", (string) file_get_contents(__DIR__.'/../files/pixel.png'));
    $legacy = Media::factory()->create(['uuid' => $uuid, 'file_name' => 'logo.png', 'mime_type' => 'image/png', 'path' => null]);
    $updatedAt = $legacy->updated_at?->toIso8601String();

    $attached = MediaLibrary::attach($legacy, bucket: 'shared');

    expect($legacy->fresh()?->path)->toBe("{$uuid}/logo.png")
        ->and($legacy->fresh()?->updated_at?->toIso8601String())->toBe($updatedAt)
        ->and($attached->getPath())->toBe("{$uuid}/logo.png");

    MediaLibrary::delete($legacy);

    Storage::disk('public')->assertExists($attached->getPath());
});

it('never pins an unsaved row to the database', function (): void {
    $media = Media::factory()->make(['uuid' => mediaUuid('unsaved'), 'file_name' => 'a.png', 'path' => null]);

    app(StoredFiles::class)->pin($media);

    expect($media->path)->toBe(mediaUuid('unsaved').'/a.png')
        ->and($media->exists)->toBeFalse();
});

it('picks a unique sub-directory when both the preferred and the canonical path are taken', function (): void {
    $media = Media::factory()->create(['uuid' => mediaUuid('crowded'), 'file_name' => 'a.png']);
    Media::factory()->create(['path' => mediaUuid('crowded').'/a.png']);
    Media::factory()->create(['path' => 'elsewhere/a.png']);

    $path = app(StoredFiles::class)->freePath($media, 'public', 'elsewhere/a.png');

    expect($path)->toStartWith(mediaUuid('crowded').'/')
        ->and($path)->toEndWith('/a.png')
        ->and(substr_count($path, '/'))->toBe(2);
});

it('leaves a directory the layout shares between media alone on media:clean', function (): void {
    app()->bind(PathGenerator::class, fn (): PathGenerator => new class implements PathGenerator
    {
        public function getPath(Media $media): string
        {
            return $media->uuid.'/';
        }

        public function getPathForVariants(Media $media): string
        {
            return 'shared-variants/';
        }
    });

    MediaLibrary::for(TestUser::query()->create(['name' => 'Jane']))->add(__DIR__.'/../files/wide.png')->toBucket('covers');
    Storage::disk('public')->put('shared-variants/someone-elses.jpg', 'x');

    $this->artisan('media:clean')->expectsOutputToContain('Removed 0')->assertSuccessful();

    Storage::disk('public')->assertExists('shared-variants/someone-elses.jpg');
});

it('defaults the checksum algorithm to sha256 when it is unset', function (): void {
    config()->set('media.checksum_algorithm', null);

    expect(app(Checksum::class)->algorithm())->toBe('sha256');
});

it('leaves variants already on the target disk where they are', function (): void {
    $media = MediaLibrary::for(TestUser::query()->create(['name' => 'Jane']))->add(__DIR__.'/../files/wide.png')->toBucket('stored');

    MediaLibrary::moveVariantsToDisk($media, 's3');

    expect($media->diskFor('thumb'))->toBe('s3')
        ->and($media->variants_disk)->toBe('s3');
    Storage::disk('s3')->assertExists($media->getPath('thumb'));
});

it('resolves an un-generated variant to the disk its definition pins', function (): void {
    $media = MediaLibrary::for(TestUser::query()->create(['name' => 'Jane']))->add(__DIR__.'/../files/note.txt')->toBucket('stored');

    expect($media->hasGeneratedVariant('thumb'))->toBeFalse()
        ->and($media->diskFor('thumb'))->toBe('s3')
        ->and($media->getPath('thumb'))->toEndWith('/variants/thumb.png');
});
