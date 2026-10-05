<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Exceptions\InvalidVariant;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\GdDriver;
use RoundlyConsulting\MediaLibrary\Variants\ImageDrivers\ImagickDriver;

/*
 * An image a driver cannot decode reported "There is no variant named [original]" (GD) or leaked
 * a raw ImagickException. Both drivers now throw the documented InvalidVariant, saying so.
 */

/** A PNG whose header reads fine and whose body no decoder can read. */
function undecodablePng(): string
{
    $header = substr((string) file_get_contents(__DIR__.'/../files/wide.png'), 0, 33);
    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    $path = sys_get_temp_dir().'/media-undecodable-'.uniqid().'.png';
    file_put_contents($path, $header.$chunk('IDAT', str_repeat("\xAB", 64)).$chunk('IEND', ''));

    return $path;
}

it('reports an undecodable image as an InvalidVariant from either driver', function (string $driver): void {
    $path = undecodablePng();

    try {
        $load = fn () => ($driver === 'gd' ? new GdDriver : new ImagickDriver)->load($path);

        expect($load)->toThrow(InvalidVariant::class, 'could not be decoded');
    } finally {
        @unlink($path);
    }
})->with(['gd', 'imagick']);

it('keeps the decoder\'s own explanation as the previous exception', function (): void {
    $path = undecodablePng();

    try {
        (new ImagickDriver)->load($path);
    } catch (InvalidVariant $exception) {
        expect($exception->getPrevious())->toBeInstanceOf(ImagickException::class);
    } finally {
        @unlink($path);
    }
})->skip(! extension_loaded('imagick'), 'needs ext-imagick');
