<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\MediaLibrary\Concerns\InteractsWithMedia;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Variants\VariantRegistrar;

/**
 * @property int $id
 * @property string|null $name
 */
final class TestUser extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'test_users';

    protected $guarded = [];

    public function registerMediaBuckets(): void
    {
        $this->addMediaBucket('avatar')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->useDisk('cold')
            ->storingVariantsOnDisk('hot')
            ->private()
            ->useFallbackUrl('https://example.com/fallback-avatar.png');

        $this->addMediaBucket('gallery')
            ->useDisk('public');

        $this->addMediaBucket('photos')
            ->useDisk('public')
            ->storingVariantsOnDisk('hot')
            ->registerVariants(function (VariantRegistrar $v): void {
                $v->add('thumb')->fit('crop')->width(16)->height(16)->format('webp');
                $v->add('display')->width(24)->format('webp')->quality(80)->queued();
            });

        $this->addMediaBucket('covers')
            ->useDisk('public')
            ->registerVariants(function (VariantRegistrar $v): void {
                $v->add('small')->width(8)->format('jpg');
                // No explicit format: the variant inherits the original's extension.
                $v->add('keepformat')->width(8);
            });
    }

    public function registerMediaVariants(?Media $media = null): void
    {
        $this->addMediaVariant('watermark')
            ->performOnBuckets('covers')
            ->width(12)
            ->format('png');
    }
}
