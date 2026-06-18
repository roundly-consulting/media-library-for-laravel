<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\GdDriver;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\ImageDriverFactory;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\ImagickDriver;

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
    if (! extension_loaded('gd')) {
        $this->markTestSkipped('ext-gd not available.');
    }

    // Simulate imagick being unavailable by preferring it while only gd is loaded.
    if (extension_loaded('imagick')) {
        $this->markTestSkipped('imagick is loaded; cannot exercise the absent-imagick fallback here.');
    }

    config()->set('media.image_driver', 'imagick');

    expect(ImageDriverFactory::make())->toBeInstanceOf(GdDriver::class);
});

it('returns the preferred driver explicitly', function (): void {
    if (! extension_loaded('gd')) {
        $this->markTestSkipped('ext-gd not available.');
    }

    expect(ImageDriverFactory::make('gd'))->toBeInstanceOf(GdDriver::class);
});
