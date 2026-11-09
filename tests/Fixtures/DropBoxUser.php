<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\MediaLibrary\Concerns\InteractsWithMedia;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;

/**
 * Buckets for the strict mime mode: one without a list, one that opts out of strict mode.
 *
 * @property int $id
 */
final class DropBoxUser extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'test_users';

    protected $guarded = [];

    public function registerMediaBuckets(): void
    {
        $this->addMediaBucket('loose')->useDisk('public');

        $this->addMediaBucket('drop')->useDisk('public')->acceptsAnyMimeType();

        $this->addMediaBucket('pictures')->useDisk('public')->acceptsMimeTypes(['image/png']);
    }
}
