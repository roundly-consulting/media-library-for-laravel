<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Exceptions\VariantDriverUnavailable;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\GdDriver;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\ImageDriverFactory;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\ImagickDriver;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/** @return callable(string): bool */
function fakeExtensions(string ...$loaded): callable
{
    return static fn (string $name): bool => in_array($name, $loaded, true);
}

it('uses gd when configured and available', function (): void {
    if (! extension_loaded('gd')) {
        $this->markTestSkipped('ext-gd not available.');
    }

    config()->set('media.image_driver', 'gd');

    expect(ImageDriverFactory::make())->toBeInstanceOf(GdDriver::class);
});

it('uses imagick by default when available', function (): void {
    if (! extension_loaded('imagick')) {
        $this->markTestSkipped('ext-imagick not available.');
    }

    config()->set('media.image_driver', 'imagick');

    expect(ImageDriverFactory::make())->toBeInstanceOf(ImagickDriver::class);
});

it('falls back from imagick to gd when imagick is absent', function (): void {
    config()->set('media.image_driver', 'imagick');

    expect(ImageDriverFactory::make(hasExtension: fakeExtensions('gd')))
        ->toBeInstanceOf(GdDriver::class);
});

it('returns the preferred driver explicitly', function (): void {
    if (! extension_loaded('gd')) {
        $this->markTestSkipped('ext-gd not available.');
    }

    expect(ImageDriverFactory::make('gd'))->toBeInstanceOf(GdDriver::class);
});

it('prefers gd when configured even though imagick is also loaded', function (): void {
    expect(ImageDriverFactory::make('gd', fakeExtensions('gd', 'imagick')))
        ->toBeInstanceOf(GdDriver::class);
});

it('prefers imagick when both extensions are loaded and gd is not requested', function (): void {
    expect(ImageDriverFactory::make('imagick', fakeExtensions('gd', 'imagick')))
        ->toBeInstanceOf(ImagickDriver::class);
});

it('throws when neither image extension is available', function (): void {
    ImageDriverFactory::make('imagick', fakeExtensions());
})->throws(VariantDriverUnavailable::class);

it('refuses an image driver typo instead of using imagick (strict config)', function (mixed $driver): void {
    config()->set('media.image_driver', $driver);

    expect(fn () => ImageDriverFactory::make(hasExtension: fakeExtensions('gd', 'imagick')))->toThrow(
        InvalidConfigurationException::class,
        'Configuration value [media.image_driver] must be one of [imagick, gd]',
    );
})->with(['typo' => ['GD'], 'unknown' => ['vips']]);

it('uses imagick when the driver is absent or blank (strict config)', function (?string $value): void {
    config()->set('media.image_driver', $value);

    expect(ImageDriverFactory::make(hasExtension: fakeExtensions('imagick')))->toBeInstanceOf(ImagickDriver::class);
})->with(['absent' => [null], 'blank' => [''], 'whitespace' => [' ']]);
