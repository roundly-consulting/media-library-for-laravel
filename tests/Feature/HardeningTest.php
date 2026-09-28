<?php

declare(strict_types=1);

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Actions\GenerateVariantsAction;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Jobs\GenerateVariantsJob;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

function hardeningUser(string $name = 'Jane'): TestUser
{
    return TestUser::query()->create(['name' => $name]);
}

it('copies media onto a null owner as global media', function (): void {
    $user = hardeningUser();
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    $copy = $media->copy(null, 'brand');

    expect($copy->model_type)->toBeNull()
        ->and($copy->model_id)->toBeNull()
        ->and($copy->bucket_name)->toBe('brand')
        ->and($copy->order_column)->toBe(1);

    Storage::disk($copy->disk)->assertExists($copy->getPath());
});

it('orders copied global media after existing global media in the bucket', function (): void {
    $first = MediaLibrary::add(__DIR__.'/../files/pixel.png')->toBucket('brand');
    $second = $first->copy(null, 'brand');

    expect($second->order_column)->toBeGreaterThan((int) $first->order_column);
});

it('returns the media unchanged when generating an empty variant set', function (): void {
    $user = hardeningUser();
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    $result = app(GenerateVariantsAction::class)->execute($media, []);

    expect($result->is($media))->toBeTrue()
        ->and($result->generated_variants)->toBe([]);
});

it('dispatches queued variants on the configured queue connection', function (): void {
    Bus::fake();
    config()->set('media.queue_connection', 'redis');
    config()->set('media.queue_name', 'media-variants');

    $user = hardeningUser();
    $user->addMedia(__DIR__.'/../files/wide.png')->toMediaBucket('photos');

    Bus::assertDispatched(GenerateVariantsJob::class, function (GenerateVariantsJob $job): bool {
        return $job->connection === 'redis' && $job->queue === 'media-variants';
    });
});

it('skips integrity checks for media without a checksum', function (): void {
    $user = hardeningUser();
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');
    $media->forceFill(['checksum' => null])->save();

    $this->artisan('media:verify')
        ->expectsOutputToContain('Verified 1 media')
        ->assertExitCode(0);
});

it('derives the extension from the content type when the url has no extension', function (): void {
    Http::fake([
        'https://cdn.example.com/asset' => Http::response(
            (string) file_get_contents(__DIR__.'/../files/pixel.png'),
            200,
            ['Content-Type' => 'image/png'],
        ),
    ]);

    $user = hardeningUser();
    $media = $user->addMediaFromUrl('https://cdn.example.com/asset')->toMediaBucket('gallery');

    expect($media->extension)->toBe('png')
        ->and($media->file_name)->toEndWith('.png');
});

it('does not delete a deduped old original living under another rows path on replace', function (): void {
    $user = hardeningUser();

    // B dedups onto A's stored path (A's uuid directory).
    $a = $user->addMedia(__DIR__.'/../files/wide.png')->preservingOriginal()->toMediaBucket('gallery');
    $b = $user->addMedia(__DIR__.'/../files/wide.png')->preservingOriginal()->toMediaBucket('gallery');
    $sharedPath = $a->getPath();
    expect($b->getPath())->toBe($sharedPath);

    // Force-delete A so B is the sole referrer, yet B's path still lives under A's uuid.
    $a->deleteWithFiles();
    Storage::disk('public')->put($sharedPath, (string) file_get_contents(__DIR__.'/../files/wide.png'));

    $b->replace(__DIR__.'/../files/sunrise.png');

    // The replace must leave the foreign-uuid path alone (it is not B's own to delete).
    Storage::disk('public')->assertExists($sharedPath);
    expect($b->getPath())->not->toBe($sharedPath);
});

it('replaces media from an uploaded file and discards the temporary source', function (): void {
    $user = hardeningUser();
    $media = $user->addMedia(__DIR__.'/../files/pixel.png')->toMediaBucket('gallery');

    $upload = new UploadedFile(__DIR__.'/../files/sunrise.png', 'sunrise.png', 'image/png', null, true);
    $replaced = $media->replace($upload);

    expect($replaced->uuid)->toBe($media->uuid);
    Storage::disk($replaced->disk)->assertExists($replaced->getPath());
});
