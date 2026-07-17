<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\DefaultFileNamer;
use RoundlyConsulting\MediaLibrary\Support\DefaultPathGenerator;
use RoundlyConsulting\MediaLibrary\Support\DefaultUrlGenerator;
use RoundlyConsulting\MediaLibrary\Support\MediaUrlResolver;

it('generates original and variant paths from the uuid', function (): void {
    $generator = new DefaultPathGenerator;
    $media = Media::factory()->make(['uuid' => 'abc']);

    expect($generator->getPath($media))->toBe('abc/')
        ->and($generator->getPathForVariants($media))->toBe('abc/variants/');
});

it('names original and variant files', function (): void {
    $namer = new DefaultFileNamer;

    expect($namer->originalFileName('photo.jpg'))->toBe('photo.jpg')
        ->and($namer->variantFileName('thumb', 'webp'))->toBe('thumb.webp');
});

it('resolves a public url through the default generator', function (): void {
    $media = Media::factory()->create(['uuid' => mediaUuid('u9'), 'file_name' => 'a.jpg', 'disk' => 'public']);

    expect((new DefaultUrlGenerator(new MediaUrlResolver))->getUrl($media))->toContain(mediaUuid('u9').'/a.jpg');
});
