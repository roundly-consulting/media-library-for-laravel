<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Events\MediaHasBeenDeleted;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\ConfigurableBucketUser;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

function rulesUser(): TestUser
{
    return TestUser::query()->create(['name' => 'Jane']);
}

/** A valid PNG padded past `$bytes` (bytes after IEND are ignored by decoders and sniffers). */
function paddedPng(int $bytes): string
{
    $path = sys_get_temp_dir().'/media-rules-'.bin2hex(random_bytes(6)).'.png';
    file_put_contents($path, (string) file_get_contents(__DIR__.'/../files/pixel.png').str_repeat("\0", $bytes));

    return $path;
}

it('enforces the bucket max file size on add', function (): void {
    $user = ConfigurableBucketUser::query()->create(['name' => 'Jane']);

    expect(fn () => MediaLibrary::for($user)->add(paddedPng(1024 * 1024 + 1))->toBucket('uploads'))
        ->toThrow(FileUnacceptableForBucket::class, 'larger');

    expect(Storage::disk('public')->allFiles())->toBe([]);
});

it('enforces the package-level max file size on a global add', function (): void {
    config()->set('media.max_file_size', 100);

    expect(fn () => MediaLibrary::add(paddedPng(200))->toBucket('brand'))
        ->toThrow(FileUnacceptableForBucket::class, 'larger');
});

it('accepts anything when the package-level limit is off', function (): void {
    config()->set('media.max_file_size', null);

    expect(MediaLibrary::add(paddedPng(200))->toBucket('brand')->exists)->toBeTrue();
});

it('enforces the bucket minimum dimensions on add', function (): void {
    expect(fn () => MediaLibrary::for(rulesUser())->add(__DIR__.'/../files/wide.png')->toBucket('documents'))
        ->toThrow(FileUnacceptableForBucket::class, 'dimensions');
});

it('enforces the bucket maximum dimensions on add', function (): void {
    $big = sys_get_temp_dir().'/media-rules-'.bin2hex(random_bytes(6)).'.png';
    imagepng(imagecreatetruecolor(4100, 120), $big);

    expect(fn () => MediaLibrary::for(rulesUser())->add($big)->toBucket('documents'))
        ->toThrow(FileUnacceptableForBucket::class, 'dimensions');
});

it('accepts an image within the bucket dimensions', function (): void {
    $fits = sys_get_temp_dir().'/media-rules-'.bin2hex(random_bytes(6)).'.png';
    imagepng(imagecreatetruecolor(120, 120), $fits);

    $media = MediaLibrary::for(rulesUser())->add($fits)->toBucket('documents');

    expect($media->width)->toBe(120);
});

it('enforces the owner bucket mime allowlist on replace', function (): void {
    $user = rulesUser();
    $media = MediaLibrary::for($user)->add(__DIR__.'/../files/pixel.png')->toBucket('avatar');

    expect(fn () => MediaLibrary::replace($media, __DIR__.'/../files/note.txt'))
        ->toThrow(FileUnacceptableForBucket::class);

    expect($media->fresh()?->mime_type)->toBe('image/png')
        ->and($media->fresh()?->verifyIntegrity())->toBeTrue();
});

it('fires MediaHasBeenDeleted for the media a single-file add replaces', function (): void {
    Event::fake([MediaHasBeenDeleted::class]);
    $user = rulesUser();

    $first = MediaLibrary::for($user)->add(__DIR__.'/../files/pixel.png')->toBucket('avatar');
    $second = MediaLibrary::for($user)->add(__DIR__.'/../files/wide.png')->toBucket('avatar');

    expect($user->getMedia('avatar')->pluck('id')->all())->toBe([$second->id]);
    Event::assertDispatched(MediaHasBeenDeleted::class, fn (MediaHasBeenDeleted $event): bool => $event->media->is($first));
});

it('keeps the previous single-file media when the replacing add is rejected', function (): void {
    $user = rulesUser();
    $first = MediaLibrary::for($user)->add(__DIR__.'/../files/pixel.png')->toBucket('avatar');

    expect(fn () => MediaLibrary::for($user)->add(__DIR__.'/../files/note.txt')->toBucket('avatar'))
        ->toThrow(FileUnacceptableForBucket::class);

    expect($first->fresh())->not->toBeNull();
});
