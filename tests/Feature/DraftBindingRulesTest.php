<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Events\MediaHasBeenDeleted;
use RoundlyConsulting\MediaLibrary\Exceptions\FileUnacceptableForBucket;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

function bindingUser(string $name = 'Jane'): TestUser
{
    return TestUser::query()->create(['name' => $name]);
}

it('generates the target bucket variants when a draft is bound', function (): void {
    Bus::fake();
    $user = bindingUser();
    $draft = MediaLibrary::draft(__DIR__.'/../files/wide.png')->toBucket('photos');

    $bound = MediaLibrary::for($user)->bindDraft((string) $draft->draft_token, 'photos');

    expect($bound->hasGeneratedVariant('thumb'))->toBeTrue()
        ->and($bound->getUrl('thumb'))->toEndWith('/variants/thumb.webp');
    Storage::disk('hot')->assertExists($bound->getPath('thumb'));
});

it('refuses to bind a draft the target bucket does not accept', function (): void {
    $user = bindingUser();
    $draft = MediaLibrary::draft(__DIR__.'/../files/note.txt')->toBucket('avatar');

    expect(fn () => MediaLibrary::for($user)->bindDraft((string) $draft->draft_token, 'avatar'))
        ->toThrow(FileUnacceptableForBucket::class);

    expect($draft->fresh()?->draft_token)->not->toBeNull()
        ->and($user->getMedia('avatar'))->toHaveCount(0);
});

it('applies the target bucket single-file rule when a draft is bound', function (): void {
    Event::fake([MediaHasBeenDeleted::class]);
    $user = bindingUser();
    $previous = MediaLibrary::for($user)->add(__DIR__.'/../files/pixel.png')->toBucket('avatar');
    $draft = MediaLibrary::draft(__DIR__.'/../files/wide.png')->toBucket('avatar');

    $bound = MediaLibrary::for($user)->bindDraft((string) $draft->draft_token, 'avatar');

    expect($user->getMedia('avatar')->pluck('id')->all())->toBe([$bound->id])
        ->and(Media::withTrashed()->find($previous->id))->toBeNull();
    Event::assertDispatched(MediaHasBeenDeleted::class, fn (MediaHasBeenDeleted $event): bool => $event->media->is($previous));
});

it('stores a bound draft on the target bucket disk and visibility', function (): void {
    $user = bindingUser();
    $draft = MediaLibrary::draft(__DIR__.'/../files/wide.png')->toBucket('avatar');
    $draftPath = $draft->getPath();
    Storage::disk('public')->assertExists($draftPath);

    $bound = MediaLibrary::for($user)->bindDraft((string) $draft->draft_token, 'avatar');

    expect($bound->disk)->toBe('cold')
        ->and($bound->visibility)->toBe('private')
        ->and($bound->fresh()?->verifyIntegrity())->toBeTrue();
    Storage::disk('public')->assertMissing($draftPath);
    expect(Storage::disk('cold')->getVisibility($bound->getPath()))->toBe('private');
});

it('keeps a deduplicated draft original for its other referrer when binding relocates it', function (): void {
    $user = bindingUser();
    $public = MediaLibrary::add(__DIR__.'/../files/wide.png')->toBucket('brand');
    $draft = MediaLibrary::draft(__DIR__.'/../files/wide.png')->toBucket('avatar');
    expect($draft->getPath())->toBe($public->getPath());

    MediaLibrary::for($user)->bindDraft((string) $draft->draft_token, 'avatar');

    expect($public->fresh()?->verifyIntegrity())->toBeTrue();
});

it('keeps unbound drafts out of global bucket reads and clears', function (): void {
    $logo = MediaLibrary::add(__DIR__.'/../files/pixel.png')->toBucket('brand');
    $draft = MediaLibrary::draft(__DIR__.'/../files/wide.png')->toBucket('brand');

    expect(MediaLibrary::bucket('brand')->pluck('id')->all())->toBe([$logo->id])
        ->and(MediaLibrary::clearBucket('brand'))->toBe(1)
        ->and($draft->fresh())->not->toBeNull();

    $this->artisan('media:clear', ['model' => '', 'bucket' => 'brand'])->assertSuccessful();

    expect($draft->fresh())->not->toBeNull();

    $bound = MediaLibrary::for(bindingUser())->bindDraft((string) $draft->draft_token, 'gallery');
    expect($bound->bucket_name)->toBe('gallery');
});

it('only flips the visibility when the bucket keeps the draft on its disk', function (): void {
    config()->set('media.disk', 'secure');
    $user = bindingUser();
    $draft = MediaLibrary::draft(__DIR__.'/../files/wide.png')->toBucket('vault');
    $path = $draft->getPath();

    $bound = MediaLibrary::for($user)->bindDraft((string) $draft->draft_token, 'vault');

    expect($bound->disk)->toBe('secure')
        ->and($bound->getPath())->toBe($path)
        ->and($bound->visibility)->toBe('private')
        ->and(Storage::disk('secure')->getVisibility($path))->toBe('private');
});

it('binds into a bucket the owner does not declare as it was uploaded', function (): void {
    $user = bindingUser();
    $draft = MediaLibrary::draft(__DIR__.'/../files/wide.png')->toBucket('anything');

    $bound = MediaLibrary::for($user)->bindDraft((string) $draft->draft_token, 'undeclared');

    expect($bound->disk)->toBe('public')
        ->and($bound->bucket_name)->toBe('undeclared')
        ->and($bound->fresh()?->verifyIntegrity())->toBeTrue();
});
