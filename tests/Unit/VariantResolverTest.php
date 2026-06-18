<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\MediaLibrary\Concerns\InteractsWithMedia;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;
use RoundlyConsulting\MediaLibrary\Variants\VariantResolver;

it('returns no variants for media without an owning model', function (): void {
    $media = new Media(['bucket_name' => 'brand']);

    expect(app(VariantResolver::class)->forMedia($media))->toBe([]);
});

it('merges bucket and model-level variants for an owner', function (): void {
    $user = TestUser::query()->create(['name' => 'Jane']);

    $variants = app(VariantResolver::class)->forOwnerBucket($user, 'covers');

    $names = array_map(fn ($variant): string => $variant->name, $variants);

    expect($names)->toContain('small')
        ->and($names)->toContain('watermark');
});

it('ignores model-level variants when the owner has no hook', function (): void {
    $owner = new class extends Model implements HasMedia
    {
        use InteractsWithMedia;

        protected $table = 'test_users';

        protected $guarded = [];

        public function registerMediaBuckets(): void
        {
            $this->addMediaBucket('default');
        }
    };

    $owner->forceFill(['name' => 'x'])->save();

    $variants = app(VariantResolver::class)->forOwnerBucket($owner, 'default');

    expect($variants)->toBe([]);
});

it('collects model-level variants from the trait hook', function (): void {
    $user = TestUser::query()->create(['name' => 'Jane']);

    $collection = $user->resolveModelMediaVariants();

    expect($collection->names())->toBe(['watermark']);
});
