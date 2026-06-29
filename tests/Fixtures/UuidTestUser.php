<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\MediaLibrary\Concerns\InteractsWithMedia;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\MediaLibrary\Variants\VariantRegistrar;

/**
 * Owner keyed by a UUID (HasUuids) — proves the media morph is owner-key-agnostic and that two
 * distinct UUID owners never collide on `model_id`.
 *
 * @property string $id
 * @property string|null $name
 */
final class UuidTestUser extends Model implements HasMedia
{
    use HasUuids;
    use InteractsWithMedia;

    protected $table = 'uuid_test_users';

    protected $guarded = [];

    public function registerMediaBuckets(): void
    {
        $this->addMediaBucket('gallery')
            ->useDisk('public');

        $this->addMediaBucket('photos')
            ->useDisk('public')
            ->storingVariantsOnDisk('hot')
            ->registerVariants(function (VariantRegistrar $v): void {
                $v->add('thumb')->fit('crop')->width(16)->height(16)->format('webp');
            });

        // Responsive ladder. 'wide.png' is 40px wide, so 16/24/64 → only 16 and 24 generate.
        $this->addMediaBucket('hero')
            ->useDisk('public')
            ->responsiveWidths([16, 24, 64]);
    }
}
