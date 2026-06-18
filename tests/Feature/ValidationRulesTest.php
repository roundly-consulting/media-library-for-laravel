<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Facades\Media;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\ConfigurableBucketUser;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;

beforeEach(function (): void {
    ConfigurableBucketUser::$strict = false;
});

it('derives mimes, size, and dimension rules from the bucket', function (): void {
    $rules = Media::rulesFor(TestUser::class, 'documents');

    expect($rules)->toBe([
        'file',
        'mimetypes:image/jpeg,image/png',
        'max:5120',
        'dimensions:min_width=100,min_height=100,max_width=4096,max_height=4096',
    ]);
});

it('only emits a mimetypes rule for a mime-only bucket', function (): void {
    $rules = Media::rulesFor(TestUser::class, 'avatar');

    expect($rules)->toBe([
        'file',
        'mimetypes:image/jpeg,image/png,image/webp',
    ]);
});

it('falls back to just file for an unconstrained bucket', function (): void {
    $rules = Media::rulesFor(TestUser::class, 'gallery');

    expect($rules)->toBe(['file']);
});

it('falls back to just file for an unknown bucket', function (): void {
    $rules = Media::rulesFor(TestUser::class, 'no-such-bucket');

    expect($rules)->toBe(['file']);
});

it('rounds a non-kilobyte-aligned max size up to whole kilobytes', function (): void {
    $rules = Media::rulesFor(ConfigurableBucketUser::class, 'uploads');

    // 1 MiB => exactly 1024 KB.
    expect($rules)->toContain('max:1024');
});

it('changes the rules when the bucket definition changes', function (): void {
    $relaxed = Media::rulesFor(ConfigurableBucketUser::class, 'uploads');

    ConfigurableBucketUser::$strict = true;

    $strict = Media::rulesFor(ConfigurableBucketUser::class, 'uploads');

    expect($relaxed)->not->toBe($strict)
        ->and($strict)->toContain('max:2048')
        ->and($strict)->toContain('dimensions:max_width=2000,max_height=1500');
});
