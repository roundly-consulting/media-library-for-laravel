<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\MediaLibrary\Contracts\FileNamer;
use RoundlyConsulting\MediaLibrary\Contracts\PathGenerator;
use RoundlyConsulting\MediaLibrary\Contracts\UrlGenerator;
use RoundlyConsulting\MediaLibrary\MediaManager;
use RoundlyConsulting\MediaLibrary\Support\DefaultFileNamer;
use RoundlyConsulting\MediaLibrary\Support\DefaultPathGenerator;
use RoundlyConsulting\MediaLibrary\Support\DefaultUrlGenerator;

it('merges the package config', function (): void {
    expect(config('media.disk'))->toBe('public')
        ->and(config('media.default_visibility'))->toBe('public');
});

it('creates the media table', function (): void {
    expect(Schema::hasTable('media'))->toBeTrue()
        ->and(Schema::hasColumns('media', [
            'uuid', 'model_type', 'model_id', 'bucket_name', 'disk', 'variants_disk',
            'checksum', 'width', 'height', 'placeholders', 'draft_token', 'draft_expires_at',
            'order_column', 'deleted_at',
        ]))->toBeTrue();
});

it('binds the seam contracts from config', function (): void {
    expect(app(PathGenerator::class))->toBeInstanceOf(DefaultPathGenerator::class)
        ->and(app(FileNamer::class))->toBeInstanceOf(DefaultFileNamer::class)
        ->and(app(UrlGenerator::class))->toBeInstanceOf(DefaultUrlGenerator::class);
});

it('binds the media manager as a singleton', function (): void {
    expect(app(MediaManager::class))->toBe(app('media'))
        ->and(app(MediaManager::class))->toBeInstanceOf(MediaManager::class);
});
