<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Contracts\ImageDriver;
use RoundlyConsulting\MediaLibrary\DataTransferObjects\RgbaImage;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\ImageDriverFactory;

/**
 * The docs promise a host can "bind your own ImageDriver in a service provider to use a
 * different engine". Every image path must therefore resolve the contract from the container
 * rather than calling the built-in factory directly — otherwise the binding is silently ignored.
 */
final class RecordingImageDriver implements ImageDriver
{
    /** @var list<string> */
    public static array $loaded = [];

    private ImageDriver $inner;

    public function __construct()
    {
        $this->inner = ImageDriverFactory::make();
    }

    public function load(string $path): ImageDriver
    {
        self::$loaded[] = $path;
        $this->inner->load($path);

        return $this;
    }

    public function width(): int
    {
        return $this->inner->width();
    }

    public function height(): int
    {
        return $this->inner->height();
    }

    public function fit(string $mode, ?int $width, ?int $height): ImageDriver
    {
        $this->inner->fit($mode, $width, $height);

        return $this;
    }

    public function resize(?int $width, ?int $height): ImageDriver
    {
        $this->inner->resize($width, $height);

        return $this;
    }

    public function format(string $format): ImageDriver
    {
        $this->inner->format($format);

        return $this;
    }

    public function quality(int $quality): ImageDriver
    {
        $this->inner->quality($quality);

        return $this;
    }

    public function background(string $color): ImageDriver
    {
        $this->inner->background($color);

        return $this;
    }

    public function sharpen(int $amount): ImageDriver
    {
        $this->inner->sharpen($amount);

        return $this;
    }

    public function encode(): string
    {
        return $this->inner->encode();
    }

    public function save(string $path): void
    {
        $this->inner->save($path);
    }

    public function rgbaPixels(int $maxSize): RgbaImage
    {
        return $this->inner->rgbaPixels($maxSize);
    }

    public function supportsFormat(string $format): bool
    {
        return $this->inner->supportsFormat($format);
    }

    public function name(): string
    {
        return 'recording';
    }
}

beforeEach(function (): void {
    RecordingImageDriver::$loaded = [];
    app()->bind(ImageDriver::class, RecordingImageDriver::class);
});

it('generates variants and placeholders through a host-bound image driver', function (): void {
    $media = TestUser::query()->create(['name' => 'Jane'])
        ->addMedia(__DIR__.'/../files/wide.png')
        ->toMediaBucket('covers');

    // One load for the placeholders, one per sync variant ('small', 'keepformat', 'watermark').
    expect(RecordingImageDriver::$loaded)->toHaveCount(4)
        ->and($media->placeholder())->toHaveKeys(['thumbhash', 'blurhash'])
        ->and($media->hasGeneratedVariant('small'))->toBeTrue();
});

it('recomputes placeholders on replace through a host-bound image driver', function (): void {
    $media = TestUser::query()->create(['name' => 'Jane'])
        ->addMedia(__DIR__.'/../files/wide.png')
        ->toMediaBucket('gallery');

    RecordingImageDriver::$loaded = [];

    $media->replace(__DIR__.'/../files/sunrise.png');

    expect(RecordingImageDriver::$loaded)->toHaveCount(1);
});
