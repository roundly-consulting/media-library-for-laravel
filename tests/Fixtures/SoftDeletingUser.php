<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use RoundlyConsulting\MediaLibrary\Concerns\InteractsWithMedia;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\MediaLibrary\Variants\VariantRegistrar;

/**
 * An owner with soft deletes, like most host models: once trashed, the media's `model` relation
 * no longer finds it.
 *
 * @property int $id
 * @property string|null $name
 */
final class SoftDeletingUser extends Model implements HasMedia
{
    use InteractsWithMedia;
    use SoftDeletes;

    protected $table = 'soft_test_users';

    protected $guarded = [];

    public function registerMediaBuckets(): void
    {
        $this->addMediaBucket('covers')
            ->useDisk('public')
            ->registerVariants(function (VariantRegistrar $v): void {
                $v->add('small')->width(8)->format('jpg');
            });
    }
}
