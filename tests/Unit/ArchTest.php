<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Exceptions\MediaLibraryException;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\Testing\Arch\ArchPresets;

/**
 * The presets replace the generic hand-written rules that were here (strict types, debug
 * leftovers). The bespoke rules below have no preset equivalent and are kept.
 */
ArchPresets::strictTypes('RoundlyConsulting\MediaLibrary');

/**
 * Media shipped NO finality rule at all — the same gap jwt had, which is how a `final` on a
 * config-swappable model reached production there (bug #4). The exemptions are the
 * documented extension points.
 *
 * Through the `$ignoring` PARAMETER, not Pest's fluent `->ignoring()`: the parameter is
 * rot-checked (a renamed class fails as stale instead of silently exempting nothing) and it
 * recovers the prefix SHADOW — Pest matches exemptions by string prefix rather than class
 * identity (pest-plugin-arch Blueprint.php:103), so an exemption silences every class whose
 * FQCN starts with it. Measured here: **neither exemption shadows anything** (nothing else in
 * `Models\` starts with `Media`, and no exception starts with `MediaLibraryException`). The
 * package does hold four latent prefix pairs — `Variant` over `VariantCollection`/
 * `VariantRegistrar`/`VariantResolver`, `PendingFileAdd` over `PendingFileAddState` — and all
 * four are final, so the guard is prospective: it fires if one of those is ever both exempted
 * and opened.
 */
ArchPresets::finalByDefault('RoundlyConsulting\MediaLibrary', [
    // `media.media_model` invites a host to subclass this; pinned by the preset below.
    Media::class,
    // The base every media error extends, so a host can catch them uniformly.
    MediaLibraryException::class,
]);

/**
 * The counter-weight, and the fleet's 7×-shipped fatal: `final` on a config-swappable model
 * is a PHP fatal the moment a host uses the seam the config documents. Media is the package
 * where that bites hardest — the observer that cleans files off disk hangs on the
 * *configured* model's events, so the seam is load-bearing for storage, not just for types.
 *
 * Only `media_model` is mapped. Media ships four config class-bindings, but the other three
 * (`path_generator`, `file_namer`, `url_generator`) are handler seams resolved through the
 * container, not Eloquent models: they have no config *default* of a model class for this
 * preset to pin, and no rows whose concrete class could be wrong.
 */
ArchPresets::swappableModelsAreNotFinal([
    Media::class => 'media.media_model',
]);

/**
 * The ban stays live across media, but three classes are exempt because their use of
 * `base64_*` is **data encoding, not cryptography** — the ban list cannot tell the two
 * apart, and media is a package that legitimately does a lot of the former:
 *
 *  - PlaceholderDataUri — builds a `data:image/png;base64,…` URI. That IS the format.
 *  - ThumbHashEncoder — packs/unpacks the thumbhash wire format, whose transport encoding
 *    is base64. Despite the name it is a perceptual image hash (an LQIP placeholder), not
 *    a security primitive.
 *  - FileAdderFactory — decodes a base64 upload payload a host handed the package.
 *
 * ## The fourth exemption is GONE, and its removal is the point of this row
 *
 * `Media::class` was exempted for one call: `Media::verifyChecksum()`'s `hash_equals()`. That
 * was flagged at the time as a closer call — a real primitive, used correctly, exempted rather
 * than silently rewritten. The flag was right and the ban was wrong: `hash_equals` left
 * CRYPTO_PRIMITIVES on 2026-07-17. It **is** PHP's constant-time compare rather than a local
 * copy of one, it has no algorithm or key to centralize, and banning it pushed callers toward
 * `$a === $b` — a timing leak that reads as a harmless simplification.
 *
 * So the exemption became dead weight, and dead weight here is expensive: `->ignoring()` is
 * scoped to a CLASS, not a function, so exempting Media for its one correct `hash_equals` call
 * blinded the package's central model to all **19** remaining primitives. Verified in-process
 * before removing: Media.php calls none of the 19, so this costs nothing and restores that
 * cover. A `hash()` dropped into Media now goes red; it did not before.
 *
 * Not exempt, deliberately: everything else. If media hand-rolls signing or key handling
 * anywhere outside these three, this still fires. `Support\Checksum` is left under the ban and
 * passes — `hash_file()` is not on the list.
 */
ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\MediaLibrary', [
    'RoundlyConsulting\MediaLibrary\Placeholders\PlaceholderDataUri',
    'RoundlyConsulting\MediaLibrary\Placeholders\ThumbHashEncoder',
    'RoundlyConsulting\MediaLibrary\Buckets\FileAdderFactory',
]);

/**
 * `media.media_model` resolves through the MediaModel seam in Support. Adopted on the
 * pre-classified rule (Swap? > 0): media has the shape the preset targets — a real Eloquent
 * model behind a `*_model` key — and it is the package whose retrofit produced the seam-bypass
 * bug the preset was written for (AddMediaAction::clearBucket() querying the packaged model,
 * orphaning files on disk).
 */
ArchPresets::modelsResolveThroughSeam(__DIR__.'/../../src', 'Support');

/**
 * The morph-key seam, guarded. Media's polymorphic owner is stored as explicit
 * `model_type`/`model_id` string columns — a string `model_id` that stores an integer, uuid or
 * ulid owner without coercion — so it never emits a raw `$table->morphs()` and passes the pin
 * on real scanned files. The guard is prospective: it reds the day a future migration reaches
 * for a raw morph and reopens the hardcoded-bigint hole a uuid/ulid host cannot survive on
 * Postgres (SQLite type affinity hides it).
 */
ArchPresets::morphColumnsUseTheSeam(__DIR__.'/../../database/migrations');

/**
 * The Dependency Policy as a test. No `alsoAllow`: media's `require` ships only
 * php/ext-fileinfo/illuminate/roundly, and the workflow installs test tooling with `--dev`,
 * so nothing legitimately lands in `require` that this must forgive. If this goes red the
 * graph is wrong — never widen the allow-list to quiet it.
 */
ArchPresets::runtimeRequireIsWhitelisted(__DIR__.'/../../composer.json');

ArchPresets::noDebuggingLeftovers();

/**
 * Kept — bespoke, no preset equivalent: the vendor roots src may touch. Narrower than
 * `runtimeRequireIsWhitelisted` (which reads composer.json), this pins the actual imports.
 */
arch('src uses only allowed namespaces')
    ->expect('RoundlyConsulting\MediaLibrary')
    ->toOnlyUse([
        'RoundlyConsulting\MediaLibrary',
        'RoundlyConsulting\MediaLibrary\Database\Factories',
        'RoundlyConsulting\PackageToolkit',
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
        'class_basename',
        'config',
        'now',
        'url',
        'data_get',
        'data_set',
        'event',
        'dispatch',
        'request',
    ]);

/** Kept — bespoke: the action shape, which no preset expresses. */
arch('actions expose a single execute method')
    ->expect('RoundlyConsulting\MediaLibrary\Actions')
    ->toHaveMethod('execute');

/** Kept — bespoke: a host can catch the whole package surface with one type. */
arch('exceptions extend the package base exception')
    ->expect('RoundlyConsulting\MediaLibrary\Exceptions')
    ->toExtend(MediaLibraryException::class)
    ->ignoring(MediaLibraryException::class);

/** Kept — bespoke: `finalByDefault` covers final, nothing in the presets pins readonly. */
arch('data transfer objects are readonly')
    ->expect('RoundlyConsulting\MediaLibrary\DataTransferObjects')
    ->toBeReadonly();
