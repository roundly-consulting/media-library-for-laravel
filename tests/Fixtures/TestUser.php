<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\MediaLibrary\Concerns\InteractsWithMedia;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;

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
    }
}
