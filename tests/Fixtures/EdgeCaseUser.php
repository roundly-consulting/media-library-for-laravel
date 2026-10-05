<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\MediaLibrary\Concerns\InteractsWithMedia;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\MediaLibrary\Variants\VariantRegistrar;

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

        // The same variant in a public and a private bucket on one disk: binding a draft from the
        // first into the second re-renders the variant onto the very path it had.
        $thumb = function (VariantRegistrar $v): void {
            $v->add('thumb')->width(8)->format('png');
        };

        $this->addMediaBucket('thumbs')->useDisk('public')->registerVariants($thumb);
        $this->addMediaBucket('thumbs-private')->useDisk('public')->private()->registerVariants($thumb);
    }
}
