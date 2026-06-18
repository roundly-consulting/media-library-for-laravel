<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\MediaLibrary\Concerns\InteractsWithMedia;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;

/**
 * A fixture whose bucket constraints are driven by a static flag, so a test can prove that
 * `Media::rulesFor()` reflects whatever the bucket currently declares (single source of truth).
 *
 * @property int $id
 */
final class ConfigurableBucketUser extends Model implements HasMedia
{
    use InteractsWithMedia;

    public static bool $strict = false;

    protected $table = 'test_users';

    protected $guarded = [];

    public function registerMediaBuckets(): void
    {
        $bucket = $this->addMediaBucket('uploads')
            ->acceptsMimeTypes(['image/png'])
            ->maxFileSize(1024 * 1024);

        if (self::$strict) {
            $bucket->maxFileSize(2 * 1024 * 1024)
                ->maxDimensions(2000, 1500);
        }
    }
}
