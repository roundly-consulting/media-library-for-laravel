<?php

declare(strict_types=1);
use RoundlyConsulting\MediaLibrary\Exceptions\MediaLibraryException;

arch('all source files use strict types')
    ->expect('RoundlyConsulting\MediaLibrary')
    ->toUseStrictTypes();

arch('no debug helpers are left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();

// Allow-list the vendor roots src may touch. Anything outside this set — any
// non-whitelisted third-party vendor — fails the suite implicitly.
arch('src uses only allowed namespaces')
    ->expect('RoundlyConsulting\MediaLibrary')
    ->toOnlyUse([
        'RoundlyConsulting\MediaLibrary',
        'RoundlyConsulting\MediaLibrary\Database\Factories',
        'Illuminate',
        'Symfony\Component\HttpFoundation\StreamedResponse',
        'Symfony\Component\HttpKernel\Exception\NotFoundHttpException',
        'Carbon',
        'Closure',
        'DateTimeInterface',
        'GdImage',
        'Imagick',
        'ImagickPixel',
        'IteratorAggregate',
        'RuntimeException',
        'Throwable',
        'Traversable',
        // native/framework helpers used unqualified
        'app',
        'config',
        'config_path',
        'database_path',
        'now',
        'url',
        'data_get',
        'data_set',
        'event',
        'dispatch',
        'request',
    ]);

arch('actions expose a single execute method')
    ->expect('RoundlyConsulting\MediaLibrary\Actions')
    ->toHaveMethod('execute');

arch('exceptions extend the package base exception')
    ->expect('RoundlyConsulting\MediaLibrary\Exceptions')
    ->toExtend(MediaLibraryException::class)
    ->ignoring(MediaLibraryException::class);

arch('data transfer objects are readonly')
    ->expect('RoundlyConsulting\MediaLibrary\DataTransferObjects')
    ->toBeReadonly();
