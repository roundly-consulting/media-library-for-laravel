<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\GdDriver;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\ImagickDriver;

/*
 * `readImage()` leaves Imagick on the LAST frame of an animation, and every later call acts on
 * that frame: a variant came out of the end of the animation, or — for an optimized GIF whose
 * later frames are small patches — as an upscaled patch. GD always reads frame 0.
 */

/**
 * A 40x40 GIF: a red frame, then either a blue frame or (optimized) a 6x6 green patch.
 */
function twoFrameGif(bool $optimized): string
{
    $animation = new Imagick;

    $first = new Imagick;
    $first->newImage(40, 40, new ImagickPixel('red'));
    $first->setImageFormat('gif');
    $animation->addImage($first);

    $second = new Imagick;
    $second->newImage($optimized ? 6 : 40, $optimized ? 6 : 40, new ImagickPixel($optimized ? 'lime' : 'blue'));
    $second->setImageFormat('gif');

    if ($optimized) {
        $second->setImagePage(40, 40, 10, 10);
    }

    $animation->addImage($second);
    $animation->setFormat('gif');

    $path = sys_get_temp_dir().'/media-animated-'.uniqid().'.gif';
    file_put_contents($path, $animation->getImagesBlob());

    return $path;
}

/** @return array{0: int, 1: int, 2: array{r: int, g: int, b: int}} width, height and first pixel */
function renderedFirstPixel(string $bytes): array
{
    $image = new Imagick;
    $image->readImageBlob($bytes);
    $color = $image->getImagePixelColor(0, 0)->getColor();

    return [$image->getImageWidth(), $image->getImageHeight(), ['r' => $color['r'], 'g' => $color['g'], 'b' => $color['b']]];
}

it('renders the first frame of an animated gif at the requested size, like gd', function (bool $optimized): void {
    $path = twoFrameGif($optimized);

    try {
        $imagick = (new ImagickDriver)->load($path)->fit('stretch', 20, 20)->format('png')->encode();
        $gd = (new GdDriver)->load($path)->fit('stretch', 20, 20)->format('png')->encode();

        expect(renderedFirstPixel($imagick))->toBe([20, 20, ['r' => 255, 'g' => 0, 'b' => 0]])
            ->and(renderedFirstPixel($gd))->toBe([20, 20, ['r' => 255, 'g' => 0, 'b' => 0]]);
    } finally {
        @unlink($path);
    }
})->with(['two full frames' => false, 'an optimized delta frame' => true])
    ->skip(! extension_loaded('imagick') || ! extension_loaded('gd'), 'needs ext-imagick and ext-gd');
