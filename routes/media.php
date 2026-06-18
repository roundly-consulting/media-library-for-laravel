<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RoundlyConsulting\MediaLibrary\Http\Controllers\MediaStreamController;
use RoundlyConsulting\MediaLibrary\Support\MediaUrlResolver;

$prefix = config('media.stream.route_prefix');
$prefix = is_string($prefix) ? $prefix : 'media';

$middleware = config('media.stream.middleware');
$middleware = is_array($middleware) ? array_values($middleware) : [];

// 'signed' is always enforced so private media is reachable only with a valid signature.
$middleware[] = 'signed';

Route::middleware($middleware)
    ->prefix($prefix)
    ->group(function (): void {
        Route::get('{media}/{variant?}', MediaStreamController::class)
            ->name(MediaUrlResolver::ROUTE_NAME);
    });
