<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use RoundlyConsulting\MediaLibrary\MediaLibraryServiceProvider;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;
use RoundlyConsulting\Testing\Database\DriverMatrix;

/**
 * Media ships exactly one CREATE and zero foreign keys. The owner is a polymorphic
 * `model_type`/`model_id` pair — and `model_id` is deliberately a **string** morph, not a
 * `foreignId`, so owners keyed by integer, uuid or ulid are all stored without coercion. That
 * is a design decision, and it means there is no FK edge to constrain.
 *
 * That shape decides what is worth pinning, and it is worth being explicit about why:
 *
 *  - **M (`toHaveRunnableMigrationOrder`) is not adopted.** One migration, zero FK edges —
 *    there is no order to get wrong. `foreignKeys: 0` would pin a number that cannot change
 *    without a schema change, over a directory with a single file.
 *  - **The R negative control (`toRejectBrokenOrderOnConnection`) is not adoptable.** It
 *    asserts the engine *refuses* a reordered set, but reversing a one-file list is the same
 *    list, and with no foreign keys Postgres has nothing to refuse. It would fail loudly by
 *    design — the assertion working correctly against a shape it does not fit, exactly as the
 *    credits row found. Not a defect, and not a reason to weaken it.
 *
 * What remains is the half that does bite: the DDL has to be something a real engine accepts.
 */
$migrations = __DIR__.'/../../database/migrations';

/**
 * P — the publish-only guards. The fleet publishes migrations timestamped rather than
 * auto-loading them; doing both runs both copies and dies on a duplicate table (bug #5, on three
 * packages). `1` pins the file count so neither check can pass over an empty or relocated
 * directory.
 */
it('never auto-loads its migration — the host publishes it', function (): void {
    expect(MediaLibraryServiceProvider::class)->toNotAutoLoadMigrations();
});

it('publishes its migration timestamp-injected into the host', function (): void {
    expect(MediaLibraryServiceProvider::class)->toPublishMigrationsTimestamped('media-migrations', 1);
});

/**
 * R (`toApplyOnConnection`) is deliberately NOT adopted yet — a HOLD, not a judgement about
 * media.
 *
 * `DriverMatrix::configure()` currently builds `connections.testing` and `connections.pgsql`
 * from the same `connectionConfig('pgsql')`, so on the pgsql leg they are one physical database
 * reached by two PDO sessions. `MigrationRunner::runFiles()` drops all tables on entry and again
 * in `finally`, so an R assertion tears the schema out from under the live suite mid-run. With
 * `executionOrder="random"` that is seed-dependent, and a green run proves nothing. Restore this
 * once the base case gives R its own database.
 *
 * The R negative control is separately not adoptable here at all: 1 migration and 0 FK edges
 * mean reversing the list is the same list and Postgres has nothing to refuse — it would fail
 * loudly by design, the assertion working correctly against a shape it does not fit.
 *
 * The pgsql leg itself stays, and it is not idle: turning the engine real is what surfaced the
 * 41 uuid fixture failures this row fixed. The round-trip below runs on whatever driver the leg
 * configured and needs no second connection.
 */

/**
 * The driver-truth pin: the env-declared driver against what the connection itself answers. It
 * makes a lying pgsql leg impossible — a base case decapitated by an un-parented
 * `defineEnvironment()` override goes red here instead of quietly running SQLite and reporting
 * itself green. It fires automatically rather than needing a human to read a skip count.
 */
it('runs on the driver the environment declared', function (): void {
    expect(DB::connection()->getDriverName())->toBe(DriverMatrix::driver());
});

/**
 * The columns the drivers genuinely disagree about, round-tripped on whatever engine the leg
 * configured. This is the assertion that found media's 41 fixture failures: `uuid` is a real
 * type on Postgres and a varchar on SQLite, so a value the suite invented could be stored on one
 * and rejected by the other. Pinning a round-trip proves the columns are usable rather than
 * merely creatable.
 */
it('round-trips the media columns on the configured engine', function (): void {
    $user = TestUser::query()->create(['name' => 'Ada']);

    $media = $user->addMedia(__DIR__.'/../files/pixel.png')
        ->withCustomProperties(['alt' => 'a pixel', 'tags' => ['x', 'y']])
        ->toMediaBucket('gallery');

    $fresh = $media->fresh();

    expect($fresh?->uuid)->toBeString()
        // A real uuid, not merely a string the driver tolerated.
        ->and($fresh?->uuid)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i')
        ->and($fresh?->getCustomProperty('alt'))->toBe('a pixel')
        ->and($fresh?->getCustomProperty('tags'))->toBe(['x', 'y'])
        ->and($fresh?->size)->toBeInt();
});
