<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Contracts\ImageDriver;
use RoundlyConsulting\MediaLibrary\Exceptions\InvalidVariant;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\GdDriver;
use RoundlyConsulting\MediaLibrary\Variants\Variant;
use RoundlyConsulting\MediaLibrary\Variants\VariantCollection;
use RoundlyConsulting\MediaLibrary\Variants\VariantRegistrar;

it('builds a variant fluently', function (): void {
    $variant = (new Variant('thumb'))
        ->width(120)
        ->height(120)
        ->fit('crop')
        ->format('webp')
        ->quality(80)
        ->background('#000000')
        ->sharpen(5)
        ->storeOnDisk('hot');

    expect($variant->name)->toBe('thumb')
        ->and($variant->getFormat())->toBe('webp')
        ->and($variant->disk())->toBe('hot');
});

it('rejects an invalid fit mode', function (): void {
    expect(fn () => (new Variant('x'))->fit('squish'))
        ->toThrow(InvalidVariant::class);
});

it('accepts every supported fit mode', function (string $mode): void {
    $set = (new Variant('x'))->width(10)->height(10)->fit($mode)->resolve(new GdDriver, 'png');

    expect($set->fit)->toBe($mode);
})->with(['contain', 'cover', 'crop', 'fill', 'stretch']);

it('rejects an unknown format', function (): void {
    expect(fn () => (new Variant('x'))->format('tiff'))
        ->toThrow(InvalidVariant::class);
});

it('rejects an out of range quality', function (): void {
    expect(fn () => (new Variant('x'))->quality(0))->toThrow(InvalidVariant::class);
    expect(fn () => (new Variant('x'))->quality(101))->toThrow(InvalidVariant::class);
});

it('normalizes jpeg to jpg', function (): void {
    expect((new Variant('x'))->format('jpeg')->getFormat())->toBe('jpg');
});

it('records per-variant queue overrides', function (): void {
    expect((new Variant('a'))->queued()->isQueued())->toBeTrue()
        ->and((new Variant('b'))->nonQueued()->isQueued())->toBeFalse()
        ->and((new Variant('c'))->isQueued())->toBeNull();
});

it('targets specific buckets', function (): void {
    $variant = (new Variant('x'))->performOnBuckets('a', 'b');

    expect($variant->appliesToBucket('a'))->toBeTrue()
        ->and($variant->appliesToBucket('c'))->toBeFalse();

    expect((new Variant('y'))->appliesToBucket('anything'))->toBeTrue();
});

it('resolves a manipulation set with config defaults', function (): void {
    config()->set('media.variant.quality', 60);
    config()->set('media.variant.background', '#abcdef');

    $set = (new Variant('thumb'))->width(50)->fit('contain')->resolve(new GdDriver, 'png');

    expect($set->name)->toBe('thumb')
        ->and($set->width)->toBe(50)
        ->and($set->fit)->toBe('contain')
        ->and($set->format)->toBe('png')
        ->and($set->quality)->toBe(60)
        ->and($set->background)->toBe('#abcdef');
});

it('throws when the driver cannot produce the requested format', function (): void {
    $driver = new class implements ImageDriver
    {
        public function load(string $path): self
        {
            return $this;
        }

        public function width(): int
        {
            return 0;
        }

        public function height(): int
        {
            return 0;
        }

        public function fit(string $mode, ?int $width, ?int $height): self
        {
            return $this;
        }

        public function resize(?int $width, ?int $height): self
        {
            return $this;
        }

        public function format(string $format): self
        {
            return $this;
        }

        public function quality(int $quality): self
        {
            return $this;
        }

        public function background(string $color): self
        {
            return $this;
        }

        public function sharpen(int $amount): self
        {
            return $this;
        }

        public function encode(): string
        {
            return '';
        }

        public function save(string $path): void {}

        public function supportsFormat(string $format): bool
        {
            return false;
        }

        public function name(): string
        {
            return 'fake';
        }
    };

    expect(fn () => (new Variant('x'))->format('webp')->resolve($driver, 'png'))
        ->toThrow(InvalidVariant::class);
});

it('inherits the source extension when no format is set', function (): void {
    $set = (new Variant('x'))->width(10)->resolve(new GdDriver, 'png');

    expect($set->format)->toBe('png');
});

it('collects variants into a registrar', function (): void {
    $registrar = new VariantRegistrar;
    $registrar->add('thumb')->width(10);
    $registrar->add('display')->width(20);

    $collection = $registrar->collection();

    expect($collection)->toBeInstanceOf(VariantCollection::class)
        ->and($collection->names())->toBe(['thumb', 'display'])
        ->and($collection->has('thumb'))->toBeTrue()
        ->and($collection->get('display')?->name)->toBe('display')
        ->and($collection->isEmpty())->toBeFalse();
});

it('filters a collection by bucket', function (): void {
    $registrar = new VariantRegistrar;
    $registrar->add('all')->width(10);
    $registrar->add('only-a')->width(10)->performOnBuckets('a');

    $forA = $registrar->collection()->forBucket('a');
    $forB = $registrar->collection()->forBucket('b');

    expect($forA)->toHaveCount(2)
        ->and($forB)->toHaveCount(1);
});

it('iterates a collection', function (): void {
    $registrar = new VariantRegistrar;
    $registrar->add('one');

    $names = [];

    foreach ($registrar->collection() as $name => $variant) {
        $names[] = $name;
    }

    expect($names)->toBe(['one']);
});
