<?php

declare(strict_types=1);

/**
 * The config contract, pinned in both directions — and media is the package that proves why
 * the REVERSE direction has to exist.
 *
 * Bug #27 was here: `media.max_file_size` was shipped, documented as the package-level upload
 * cap, and **nothing read it** — an upload endpoint with no size limit, under a fully green
 * suite. Only the reverse direction ("every shipped leaf is read") can see that class of bug;
 * a forward-only contract is satisfied by a config file that ships anything at all.
 *
 * The near-miss is worth recording too: a first attempt at #27 used a regex over the raw file
 * text, which was satisfied by a *docblock mention* of the key and stayed green with the fix
 * reverted. This expectation scrapes source **tokens**, so a comment is a comment and never a
 * read.
 *
 * Forward is shops #18's shape: a key the code reads that the file never ships (there, the whole
 * store-credit feature read `shops.payments.*` against a file shipping `payment.*`).
 */
it('ships exactly the config keys it reads', function (): void {
    expect(__DIR__.'/../../config/media.php')->toSatisfyConfigContract(__DIR__.'/../../src', [
        // Several real reads never appear as a `config(` token:
        //  - `media.media_model` goes through ModelResolver::for(...) — the seam that drives
        //    the whole model swap;
        //  - `media.path_generator` / `media.file_namer` / `media.url_generator` are bound by
        //    the provider's bindSeamFromConfig(...)/bindUrlGenerator();
        //  - `media.stream.middleware` and `media.responsive.widths` are read through the
        //    provider's configArray(...) helper.
        // The prefix is what makes those literals visible to the scraper.
        'extraReadPrefixes' => ['media.'],

        // NOT a config key: `media.php` is the ROUTES filename, from the provider's
        // `->hasRoutes('media.php', enabledVia: 'media.stream.enabled')`. The prefix scraper
        // above matches any string literal under `media.`, and a routes file named after its
        // package collides with that. Listed here rather than dropping the prefix, because the
        // prefix is what makes the four seam keys visible. This entry is rot-proof: if the
        // literal ever disappears, a stale entry that silences nothing is itself a failure.
        'allowUnshipped' => ['media.php'],

        // Deliberately NO `excludeFromReverse` for the provider. The testing README's own
        // example excludes the service provider on the grounds that "a render is not a read" —
        // but MediaLibraryServiceProvider::aboutData() calls config('media.…') for real, and
        // for several keys (table_name, image_driver, drafts.ttl) it is a genuine reader.
        // Excluding it would discard readers and weaken the direction that caught #27.
    ]);
});
