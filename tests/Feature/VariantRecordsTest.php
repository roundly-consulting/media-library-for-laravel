<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RoundlyConsulting\MediaLibrary\Exceptions\InvalidVariant;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

function recordsUser(string $name = 'Jane'): TestUser
{
    return TestUser::query()->create(['name' => $name]);
}

/** Copy a fixture to a temp directory under a chosen name (e.g. camera-style upper-case). */
function fixtureAs(string $fixture, string $name): string
{
    $directory = sys_get_temp_dir().'/media-records-'.bin2hex(random_bytes(6));
    mkdir($directory);
    copy(__DIR__.'/../files/'.$fixture, $directory.'/'.$name);

    return $directory.'/'.$name;
}

it('records the file each variant was written to', function (): void {
    Bus::fake();
    $media = MediaLibrary::for(recordsUser())->add(__DIR__.'/../files/wide.png')->toBucket('photos');

    // toEqual, not toBe: a jsonb column hands the keys back in its own order.
    expect($media->fresh()?->generated_variants)->toEqual([
        'thumb' => ['file_name' => 'thumb.webp', 'format' => 'webp', 'disk' => 'hot'],
    ]);
});

it('resolves an inherited-format variant of an upper-case camera upload', function (): void {
    $media = MediaLibrary::for(recordsUser())->add(fixtureAs('landscape.jpg', 'IMG_0001.JPG'))->toBucket('covers');

    expect($media->getPath('keepformat'))->toEndWith('/variants/keepformat.jpg')
        ->and($media->getUrl('keepformat'))->toEndWith('/variants/keepformat.jpg');
    Storage::disk('public')->assertExists($media->getPath('keepformat'));
});

it('resolves an inherited-format variant of a format the drivers cannot write', function (): void {
    $bmp = sys_get_temp_dir().'/media-records-'.bin2hex(random_bytes(6)).'.bmp';
    $image = imagecreatetruecolor(20, 10);
    imagebmp($image, $bmp);

    $media = MediaLibrary::for(recordsUser())->add($bmp)->toBucket('covers');

    expect($media->getPath('keepformat'))->toEndWith('/variants/keepformat.jpg');
    Storage::disk('public')->assertExists($media->getPath('keepformat'));
})->skip(fn (): bool => ! function_exists('imagebmp'), 'GD without BMP support.');

it('builds a srcset from the files actually written', function (): void {
    $media = MediaLibrary::for(recordsUser())->add(fixtureAs('wide.png', 'WIDE.PNG'))->toBucket('banner');

    expect($media->srcset())->toContain('responsive-16.png 16w')
        ->and($media->srcset())->not->toContain('.PNG');
});

it('keeps valid variant files of an upper-case upload on media:clean', function (): void {
    $media = MediaLibrary::for(recordsUser())->add(fixtureAs('landscape.jpg', 'IMG_0002.JPG'))->toBucket('covers');

    $this->artisan('media:clean')->expectsOutputToContain('Removed 0')->assertSuccessful();

    Storage::disk('public')->assertExists($media->getPath('keepformat'));
});

it('reads and deletes a variant on the disk its own storeOnDisk() chose', function (): void {
    $media = MediaLibrary::for(recordsUser())->add(__DIR__.'/../files/wide.png')->toBucket('stored');

    expect($media->diskFor('thumb'))->toBe('s3');
    Storage::disk('s3')->assertExists($media->getPath('thumb'));

    MediaLibrary::delete($media);

    expect(Storage::disk('s3')->allFiles())->toBe([]);
});

it('streams a per-variant-disk variant through the signed route', function (): void {
    $media = MediaLibrary::for(recordsUser())->add(__DIR__.'/../files/wide.png')->toBucket('stored');

    $response = $this->get(URL::temporarySignedRoute('media.stream', now()->addMinutes(5), [
        'media' => $media->uuid,
        'variant' => 'thumb',
    ]));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toBe('image/png');
});

it('drops variants the target bucket does not define when media moves', function (): void {
    Bus::fake();
    $user = recordsUser();
    $media = MediaLibrary::for($user)->add(__DIR__.'/../files/wide.png')->toBucket('photos');
    $thumb = $media->getPath('thumb');

    MediaLibrary::move($media, to: $user, bucket: 'gallery');

    expect(MediaLibrary::variants($media)->generated())->toBe([])
        ->and(MediaLibrary::variants($media)->missing())->toBe([])
        ->and(fn () => $media->getUrl('thumb'))->toThrow(InvalidVariant::class);
    Storage::disk('hot')->assertMissing($thumb);
});

it('generates the target bucket variants when media moves into it', function (): void {
    $user = recordsUser();
    $media = MediaLibrary::for($user)->add(__DIR__.'/../files/wide.png')->toBucket('gallery');

    MediaLibrary::move($media, to: $user, bucket: 'covers');

    expect(MediaLibrary::variants($media)->missing())->toBe([]);
    Storage::disk('public')->assertExists($media->getPath('small'));
});

it('copies only the variants the target bucket defines', function (): void {
    Bus::fake();
    $user = recordsUser();
    $media = MediaLibrary::for($user)->add(__DIR__.'/../files/wide.png')->toBucket('photos');

    $copy = MediaLibrary::copy($media, to: $user, bucket: 'gallery');

    expect(MediaLibrary::variants($copy)->generated())->toBe([]);
    Storage::disk('hot')->assertExists($media->getPath('thumb'));
});

it('re-records a variant whose output file changed and removes the stale file', function (): void {
    $media = MediaLibrary::for(recordsUser())->add(__DIR__.'/../files/wide.png')->toBucket('covers');
    $media->generated_variants = [
        'small' => ['file_name' => 'small.webp', 'format' => 'webp', 'disk' => 'public'],
    ] + ($media->generated_variants ?? []);
    $media->save();
    Storage::disk('public')->put($media->getPath('small'), 'stale');
    $stale = $media->getPath('small');

    MediaLibrary::regenerate($media, only: ['small'], force: true);

    expect($media->getPath('small'))->toEndWith('/variants/small.jpg');
    Storage::disk('public')->assertMissing($stale);
    Storage::disk('public')->assertExists($media->getPath('small'));
});

it('sees the variants a sync queue already rendered, so a later save cannot orphan them', function (): void {
    config()->set('queue.default', 'sync');
    $user = recordsUser();

    $media = MediaLibrary::for($user)->add(__DIR__.'/../files/wide.png')->toBucket('photos');

    expect($media->hasGeneratedVariant('display'))->toBeTrue();

    MediaLibrary::move($media, to: $user, bucket: 'gallery');

    expect(Storage::disk('hot')->allFiles())->toBe([]);
});
