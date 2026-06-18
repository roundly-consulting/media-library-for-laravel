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

        // Responsive ladder. 'wide.png' is 40px wide, so 16/24/64 → only 16 and 24 generate.
        $this->addMediaBucket('hero')
            ->useDisk('public')
            ->storingVariantsOnDisk('hot')
            ->responsiveWidths([16, 24, 64]);

        // Responsive ladder on a single-disk bucket, used for srcset/markup assertions.
        $this->addMediaBucket('banner')
            ->useDisk('public')
            ->responsiveWidths([16, 24]);

        // Responsive ladder that re-encodes every width to webp.
        $this->addMediaBucket('webphero')
            ->useDisk('public')
            ->responsiveWidths([16])
            ->responsiveFormat('webp');

        // Fully-constrained bucket used to derive validation rules from its definition.
        $this->addMediaBucket('documents')
            ->useDisk('public')
            ->acceptsMimeTypes(['image/jpeg', 'image/png'])
            ->maxFileSize(5 * 1024 * 1024)
            ->minDimensions(100, 100)
            ->maxDimensions(4096, 4096);
    }

    public function registerMediaVariants(?Media $media = null): void
    {
        $this->addMediaVariant('watermark')
            ->performOnBuckets('covers')
            ->width(12)
            ->format('png');
    }
}
