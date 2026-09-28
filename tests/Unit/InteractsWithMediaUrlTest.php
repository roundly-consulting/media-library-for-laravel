<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

function userWithMedia(array $attributes = []): array
{
    $user = TestUser::query()->create(['name' => 'Jane']);

    $media = Media::factory()->create(array_merge([
        'model_type' => $user->getMorphClass(),
        'model_id' => $user->id,
        'bucket_name' => 'avatar',
        'uuid' => mediaUuid('trait-uuid'),
        'file_name' => 'a.jpg',
        'disk' => 'public',
        'visibility' => 'public',
    ], $attributes));

    return [$user, $media];
}

it('returns the first media url for a bucket', function (): void {
    [$user] = userWithMedia();

    expect($user->getFirstMediaUrl('avatar'))->toContain(mediaUuid('trait-uuid').'/a.jpg');
});

it('returns the first media variant url', function (): void {
    [$user] = userWithMedia([
        'disk' => 'public',
        'variants_disk' => 'hot',
        'generated_variants' => ['thumb' => ['file_name' => 'thumb.jpg', 'format' => 'jpg', 'disk' => 'hot']],
        'extension' => 'jpg',
    ]);

    expect($user->getFirstMediaUrl('avatar', 'thumb'))->toContain(mediaUuid('trait-uuid').'/variants/thumb');
});

it('returns the bucket fallback url when the bucket is empty', function (): void {
    $user = TestUser::query()->create(['name' => 'Jane']);

    expect($user->getFirstMediaUrl('avatar'))->toBe('https://example.com/fallback-avatar.png');
});

it('returns a temporary url for the first private media', function (): void {
    [$user] = userWithMedia(['disk' => 'secure', 'visibility' => 'private']);

    $url = $user->getFirstTemporaryUrl('avatar');

    expect($url)->toContain('/media/'.mediaUuid('trait-uuid'))
        ->and($url)->toContain('signature=');
});

it('honours an explicit expiry on the temporary url helper', function (): void {
    [$user] = userWithMedia(['disk' => 'secure', 'visibility' => 'private']);

    $url = $user->getFirstTemporaryUrl('avatar', '', CarbonImmutable::now()->addMinutes(30));

    expect($url)->toContain('expires=');
});

it('uses the configured default lifetime when no expiry is given', function (): void {
    config()->set('media.temporary_url_default_lifetime', 'not-a-number');

    [$user] = userWithMedia(['disk' => 'secure', 'visibility' => 'private']);

    expect($user->getFirstTemporaryUrl('avatar'))->toContain('/media/'.mediaUuid('trait-uuid'));
});

it('returns the fallback url from the temporary helper when the bucket is empty', function (): void {
    $user = TestUser::query()->create(['name' => 'Jane']);

    expect($user->getFirstTemporaryUrl('avatar'))->toBe('https://example.com/fallback-avatar.png');
});
