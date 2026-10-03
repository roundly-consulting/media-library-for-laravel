<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use RoundlyConsulting\MediaLibrary\Http\Controllers\MediaStreamController;
use RoundlyConsulting\MediaLibrary\Support\MediaConfig;
use RoundlyConsulting\MediaLibrary\Support\MediaUrlResolver;

$prefix = MediaConfig::streamRoutePrefix();

$middleware = MediaConfig::streamMiddleware();

// 'signed' is always enforced so private media is reachable only with a valid signature.
$middleware[] = 'signed';

Route::middleware($middleware)
    ->prefix($prefix)
    ->group(function (): void {
        Route::get('{media}/{variant?}', MediaStreamController::class)
            ->name(MediaUrlResolver::ROUTE_NAME);
    });
