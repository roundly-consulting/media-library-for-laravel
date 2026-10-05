<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\MediaLibrary\Concerns\InteractsWithMedia;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;

/**
 * Buckets for the failure paths: storage that refuses writes, buckets that move a bound draft
 * to other storage, and single-file buckets.
 *
 * @property int $id
 * @property string|null $name
 */
final class EdgeCaseUser extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'test_users';

    protected $guarded = [];

    public function registerMediaBuckets(): void
    {
        // Every write to this disk fails, reported as `false`.
        $this->addMediaBucket('unwritable')->useDisk('flaky');

        // Binding a draft here moves its original to another disk and visibility.
        $this->addMediaBucket('vaulted')->useDisk('cold')->private();

        // A disk that takes writes but refuses visibility changes.
        $this->addMediaBucket('sticky')->useDisk('sticky')->private();
    }
}
