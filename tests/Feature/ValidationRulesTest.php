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

it('applies the configured default size to a mime-only bucket', function (): void {
    $rules = Media::rulesFor(TestUser::class, 'avatar');

    // 256 MiB (the shipped media.max_file_size) => 262144 KB.
    expect($rules)->toBe([
        'file',
        'mimetypes:image/jpeg,image/png,image/webp',
        'max:262144',
    ]);
});

it('applies the configured default size to an unconstrained bucket', function (): void {
    $rules = Media::rulesFor(TestUser::class, 'gallery');

    expect($rules)->toBe(['file', 'max:262144']);
});

it('applies the configured default size to an unknown bucket', function (): void {
    $rules = Media::rulesFor(TestUser::class, 'no-such-bucket');

    expect($rules)->toBe(['file', 'max:262144']);
});

it('honours a configured max file size', function (): void {
    config()->set('media.max_file_size', 2 * 1024 * 1024);

    expect(Media::rulesFor(TestUser::class, 'gallery'))->toBe(['file', 'max:2048']);
});

it('emits no size rule when the package level limit is disabled', function (): void {
    config()->set('media.max_file_size', null);

    expect(Media::rulesFor(TestUser::class, 'gallery'))->toBe(['file']);
});

it('lets a bucket override the configured default size', function (): void {
    config()->set('media.max_file_size', 2 * 1024 * 1024);

    // TestUser's `documents` bucket declares maxFileSize(5 MiB), which must win over the default.
    expect(Media::rulesFor(TestUser::class, 'documents'))->toContain('max:5120')
        ->and(Media::rulesFor(TestUser::class, 'documents'))->not->toContain('max:2048');
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
