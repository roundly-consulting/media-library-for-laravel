<?php

declare(strict_types=1);
use RoundlyConsulting\MediaLibrary\Exceptions\MediaLibraryException;

arch('all source files use strict types')
    ->expect('RoundlyConsulting\MediaLibrary')
    ->toUseStrictTypes();

arch('no debug helpers are left behind')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();

arch('the package does not depend on acme')
    ->expect('Acme')
    ->not->toBeUsed();

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
