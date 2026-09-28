<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Actions\AttachMediaAction;
use RoundlyConsulting\MediaLibrary\Actions\DeleteMediaAction;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

function attachUser(string $name = 'Jane'): TestUser
{
    return TestUser::query()->create(['name' => $name]);
}

it('attaches global media to a model without copying bytes', function (): void {
    $logo = MediaLibrary::add(__DIR__.'/../files/wide.png')->preservingOriginal()->toBucket('brand');

    $filesBefore = Storage::disk('public')->allFiles();

    $user = attachUser();
    $attached = $user->attachMedia($logo, 'gallery');

    // A genuinely new row for the target owner/bucket...
    expect($attached->id)->not->toBe($logo->id)
        ->and($attached->uuid)->not->toBe($logo->uuid)
        ->and($attached->model_type)->toBe($user->getMorphClass())
        ->and($attached->model_id)->toBe($user->getKey())
        ->and($attached->bucket_name)->toBe('gallery')
        // ...sharing the very same stored original.
        ->and($attached->getPath())->toBe($logo->getPath())
        ->and($attached->checksum)->toBe($logo->checksum);

    // Zero bytes copied: no new original file was written.
    expect(Storage::disk('public')->allFiles())->toBe($filesBefore);
    expect($user->getMedia('gallery'))->toHaveCount(1);
});

it('generates the target bucket variants for the attached row', function (): void {
    $logo = MediaLibrary::add(__DIR__.'/../files/wide.png')->preservingOriginal()->toBucket('brand');

    $user = attachUser();
    $attached = $user->attachMedia($logo, 'covers');

    // The source (brand) has no variants; the target (covers) defines `small`, generated per-row.
    expect($attached->hasGeneratedVariant('small'))->toBeTrue();
    Storage::disk('public')->assertExists($attached->getPath('small'));

    // The shared original is untouched by the per-row variant generation.
    Storage::disk('public')->assertExists($logo->getPath());
})->skip(fn (): bool => ! extension_loaded('imagick') && ! extension_loaded('gd'), 'No image driver available.');

it('keeps the shared original until the last referrer is deleted', function (): void {
    $logo = MediaLibrary::add(__DIR__.'/../files/wide.png')->preservingOriginal()->toBucket('brand');

    $user = attachUser();
    $attached = $user->attachMedia($logo, 'gallery');

    $shared = $logo->getPath();

    // Delete the source: the attached row still references the file.
    app(DeleteMediaAction::class)->execute($logo);
    Storage::disk('public')->assertExists($shared);

    // Delete the last referrer: now the physical original goes.
    app(DeleteMediaAction::class)->execute($attached->fresh());
    Storage::disk('public')->assertMissing($shared);
});

it('attaches a non-image global media into another global bucket without variants', function (): void {
    $source = MediaLibrary::add(__DIR__.'/../files/wide.png')->preservingOriginal()->toBucket('brand');
    // Pretend the source is a non-image so no variants are generated for the new row.
    $source->mime_type = 'application/pdf';
    $source->save();

    $attached = app(AttachMediaAction::class)->execute($source, null, 'shared');

    expect($attached->model_type)->toBeNull()
        ->and($attached->model_id)->toBeNull()
        ->and($attached->bucket_name)->toBe('shared')
        ->and($attached->getPath())->toBe($source->getPath())
        ->and($attached->generated_variants)->toBe([])
        ->and($attached->order_column)->toBe(1);
});

it('counts the attached row in the refcount on a move', function (): void {
    $logo = MediaLibrary::add(__DIR__.'/../files/wide.png')->preservingOriginal()->toBucket('brand');

    $user = attachUser();
    $attached = $user->attachMedia($logo, 'gallery');

    $shared = $logo->getPath();

    // Moving the attached row to another disk must leave the source for the still-referencing logo.
    $attached->moveToDisk('cold');

    Storage::disk('public')->assertExists($shared);
    Storage::disk('cold')->assertExists($attached->fresh()?->getPath());
});
