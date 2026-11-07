<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Tests\ConfiguredModelTestCase;
use RoundlyConsulting\MediaLibrary\Tests\PublishSandboxTestCase;
use RoundlyConsulting\MediaLibrary\Tests\TestCase;

uses(TestCase::class)->in(__DIR__.'/Feature', __DIR__.'/Unit');

// The media-model swap must be configured before the app boots, so its tests get their own case.
uses(ConfiguredModelTestCase::class)->in(__DIR__.'/Configured');

// Publishing writes files: into a throwaway database/ set before boot, never the testbench
// skeleton every parallel process migrates from.
uses(PublishSandboxTestCase::class)->in(__DIR__.'/Publish');

/**
 * A deterministic, VALID uuid derived from a readable label.
 *
 * The suite used to write literals like 'stream-uuid' or 'u1' straight into `media.uuid`.
 * SQLite stores that column as a varchar and accepts anything; Postgres has a real `uuid`
 * type and rejects them outright (22P02: invalid input syntax for type uuid), so 41 cases
 * across 8 files failed the moment the suite met a real engine.
 *
 * This is a fixture bug, not a shipped one: the package only ever writes `Str::uuid()`
 * (AddMediaAction, AttachMediaAction, CopyMediaAction), so a value like 'u1' could never
 * exist in a production media table. But it is exactly the shape the toolkit's own pgsql
 * leg found — a suite quietly asserting against something the driver would refuse — and
 * the labels are load-bearing for readability, so they are hashed rather than replaced
 * with opaque uuids.
 *
 * The uuid also drives the default storage path, so any fixture that writes the file
 * itself must derive the path from this same value.
 */
function mediaUuid(string $label): string
{
    $hex = md5($label);

    return sprintf(
        '%s-%s-%s-%s-%s',
        substr($hex, 0, 8),
        substr($hex, 8, 4),
        substr($hex, 12, 4),
        substr($hex, 16, 4),
        substr($hex, 20, 12),
    );
}

/**
 * The bytes each generated variant of `$media` holds on its disk, keyed by name.
 *
 * @return array<string, int>
 */
function variantBytesOnDisk(Media $media): array
{
    $bytes = [];

    foreach (MediaLibrary::variants($media)->generated() as $name) {
        $bytes[$name] = Storage::disk($media->diskFor($name))->size($media->getPath($name));
    }

    ksort($bytes);

    return $bytes;
}

/** Every event of `$class` fired from now on, in order. */
function collectEvents(string $class): ArrayObject
{
    $events = new ArrayObject;

    Event::listen($class, static function (object $event) use ($events): void {
        $events[] = $event;
    });

    return $events;
}

/** Strip the recorded sizes, as on a row written before sizes were. */
function withoutVariantSizes(Media $media): Media
{
    $records = $media->generated_variants ?? [];

    foreach (array_keys($records) as $name) {
        unset($records[$name]['size']);
    }

    Media::query()->whereKey($media->getKey())->toBase()->update(['generated_variants' => json_encode($records)]);

    return $media->fresh() ?? throw new RuntimeException('Media row is gone.');
}

/** Overwrite one stored record field, as if an earlier render had written it. */
function withVariantRecord(Media $media, string $variant, array $fields): Media
{
    $records = $media->generated_variants ?? [];
    $records[$variant] = array_merge($records[$variant], $fields);

    Media::query()->whereKey($media->getKey())->toBase()->update(['generated_variants' => json_encode($records)]);

    return $media->fresh() ?? throw new RuntimeException('Media row is gone.');
}
