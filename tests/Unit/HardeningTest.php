<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\MediaLibrary\Buckets\BucketValidationRules;
use RoundlyConsulting\MediaLibrary\Buckets\MediaBucket;
use RoundlyConsulting\MediaLibrary\Concerns\InteractsWithMedia;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\MediaLibrary\Exceptions\InvalidVariant;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\FileTransfer;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\GdDriver;
use RoundlyConsulting\MediaLibrary\Variants\Variant;
use RoundlyConsulting\MediaLibrary\Variants\VariantResolver;

it('copies a byte range from a stream into the output buffer', function (): void {
    $stream = fopen('php://memory', 'r+b');
    fwrite($stream, 'abcdefghij');
    rewind($stream);

    ob_start();
    Media::copyRange($stream, 2, 4);
    $output = ob_get_clean();

    expect($output)->toBe('cdef');
});

it('stops copying when the stream is shorter than the requested range', function (): void {
    $stream = fopen('php://memory', 'r+b');
    fwrite($stream, 'abc');
    rewind($stream);

    ob_start();
    Media::copyRange($stream, 0, 1024);
    $output = ob_get_clean();

    expect($output)->toBe('abc');
});

it('exposes the buckets a variant targets', function (): void {
    $variant = (new Variant('thumb'))->performOnBuckets('avatar', 'gallery');

    expect($variant->buckets())->toBe(['avatar', 'gallery']);
});

it('falls back to a bare file rule when the model is not media-aware', function (): void {
    // A class that doesn't implement HasMedia resolves to a null bucket, which yields the default.
    $rules = app(BucketValidationRules::class)->forModel(PlainModel::class, 'avatar');

    expect($rules)->toBe(['file']);
});

it('returns no model-level variants for a non-model media owner', function (): void {
    $owner = new NonModelMediaOwner;

    $variants = app(VariantResolver::class)->forOwnerBucket($owner, 'default', null);

    expect($variants)->toBe([]);
});

it('does not register any bucket by default', function (): void {
    $owner = new MediaAwareWithoutVariants;
    $owner->registerMediaBuckets();

    expect($owner->resolveMediaBucket('anything'))->toBeNull();
});

it('returns false from a file transfer when the source stream is unreadable on a cross-disk copy', function (): void {
    $copied = app(FileTransfer::class)->copy('public', 'missing/file.png', 'cold', 'dest.png', 'public');

    expect($copied)->toBeFalse();
});

it('throws when GD cannot decode the source image', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'media_');
    file_put_contents($path, 'not-an-image');

    try {
        (new GdDriver)->load($path);
    } finally {
        @unlink($path);
    }
})->throws(InvalidVariant::class);

it('keeps source dimensions when GD resizes with neither width nor height', function (): void {
    $bytes = (new GdDriver)
        ->load(__DIR__.'/../files/wide.png')
        ->resize(null, null)
        ->encode();

    $info = getimagesizefromstring($bytes);

    expect($info[0])->toBe(40)
        ->and($info[1])->toBe(20);
});

it('encodes a variant to avif when the GD build supports it', function (): void {
    $info = gd_info();

    if (($info['AVIF Support'] ?? false) !== true) {
        $this->markTestSkipped('GD AVIF support not available.');
    }

    $bytes = (new GdDriver)
        ->load(__DIR__.'/../files/wide.png')
        ->format('avif')
        ->encode();

    expect($bytes)->not->toBe('');
});

it('reports GD format support across every branch', function (): void {
    $driver = new GdDriver;
    $info = gd_info();

    expect($driver->supportsFormat('jpg'))->toBe(($info['JPEG Support'] ?? false) === true)
        ->and($driver->supportsFormat('jpeg'))->toBe(($info['JPEG Support'] ?? false) === true)
        ->and($driver->supportsFormat('png'))->toBe(($info['PNG Support'] ?? false) === true)
        ->and($driver->supportsFormat('webp'))->toBe(($info['WebP Support'] ?? false) === true)
        ->and($driver->supportsFormat('gif'))->toBe(($info['GIF Create Support'] ?? false) === true)
        ->and($driver->supportsFormat('avif'))->toBe(($info['AVIF Support'] ?? false) === true)
        ->and($driver->supportsFormat('bogus'))->toBeFalse();
});

final class PlainModel extends Model
{
    protected $table = 'plain_models';
}

final class MediaAwareWithoutVariants extends Model implements HasMedia
{
    use InteractsWithMedia;

    protected $table = 'media_aware_without_variants';
}

/**
 * A {@see HasMedia} owner that is NOT an Eloquent model, exercising the resolver's
 * non-model branch (which has no model-level variant hook).
 */
final class NonModelMediaOwner implements HasMedia
{
    /** @var array<string, MediaBucket> */
    private array $buckets = [];

    public function registerMediaBuckets(): void {}

    public function addMediaBucket(string $name): MediaBucket
    {
        return $this->buckets[$name] = new MediaBucket($name);
    }

    public function resolveMediaBucket(string $name): ?MediaBucket
    {
        $this->registerMediaBuckets();

        return $this->buckets[$name] ?? null;
    }
}
