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
 */
ArchPresets::finalByDefault('RoundlyConsulting\MediaLibrary')
    ->ignoring([
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
 * The fourth is a closer call and is recorded rather than waved through: Media::verifyChecksum()
 * uses `hash_equals()` for a constant-time compare of the stored checksum against a re-hash.
 * That is a real primitive on the ban list, used correctly — but the preset's actual intent is
 * "route this through crypto-for-laravel", and media does not `require` crypto-for-laravel.
 * Adding that dependency is a cross-package decision, not a test-adoption one, so the call site
 * is exempted here and flagged rather than silently rewritten.
 *
 * Not exempt, deliberately: everything else. If media hand-rolls signing or key handling
 * anywhere outside these four, this still fires. `Support\Checksum` is left under the ban and
 * passes — `hash_file()` is not on the list.
 */
ArchPresets::noLocalCryptoPrimitives('RoundlyConsulting\MediaLibrary')
    ->ignoring([
        'RoundlyConsulting\MediaLibrary\Placeholders\PlaceholderDataUri',
        'RoundlyConsulting\MediaLibrary\Placeholders\ThumbHashEncoder',
        'RoundlyConsulting\MediaLibrary\Buckets\FileAdderFactory',
        Media::class,
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
