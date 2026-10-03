<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use RoundlyConsulting\MediaLibrary\Tests\PublishSandboxTestCase;

/**
 * Publishing runs against a throwaway database/ ({@see PublishSandboxTestCase}), never the
 * testbench skeleton every parallel process migrates from.
 */
it('publishes into the sandbox, never the shared skeleton', function (): void {
    expect(database_path('migrations'))->toContain('media-publish-');
});

/**
 * Migrations are PUBLISH-ONLY (fleet policy): the package never loads them, the host publishes
 * them and runs `php artisan migrate`.
 */
it('never auto-loads its migrations', function (): void {
    $packaged = realpath(__DIR__.'/../../database/migrations');

    $loaded = array_map(
        fn (string $path): string => (string) realpath($path),
        app('migrator')->paths(),
    );

    expect($loaded)->not->toContain($packaged);
});

it('publishes each migration into the host timestamped', function (): void {
    $sources = glob(__DIR__.'/../../database/migrations/*.php') ?: [];

    expect($sources)->toHaveCount(1);

    Artisan::call('vendor:publish', ['--tag' => 'media-migrations', '--force' => true]);

    $published = File::glob(database_path('migrations/*_create_media_table.php'));

    expect($published)->toHaveCount(1)
        ->and(basename((string) $published[0]))->toMatch('/^\d{4}_\d{2}_\d{2}_\d{6}_create_media_table\.php$/');
});

/**
 * The structural, engine-independent order pin: SQLite silently accepts a table that references a
 * missing parent, so only reading the sources can prove the publish order is runnable. Media ships
 * one CREATE and no foreign keys today — this pin is what makes that stay true.
 */
it('creates every foreign key target before the table that references it', function (): void {
    $sources = glob(__DIR__.'/../../database/migrations/*.php') ?: [];
    sort($sources);

    expect($sources)->not->toBeEmpty();

    /** @var array<string, int> $createdAt */
    $createdAt = [];
    /** @var list<array{child: string, parent: string, position: int}> $edges */
    $edges = [];
    $alters = [];

    // The media table's name is config-driven, so its Schema::create() takes a variable.
    $configuredTable = is_string($name = config('media.table_name')) ? $name : 'media';

    foreach ($sources as $position => $source) {
        $body = (string) file_get_contents($source);

        preg_match_all("/Schema::create\(\s*(?:'([a-z0-9_]+)'|\\\$[a-zA-Z_]+)/", $body, $creates);

        foreach ($creates[1] as $table) {
            $createdAt[$table === '' ? $configuredTable : $table] ??= $position;
        }

        preg_match_all("/Schema::table\(\s*(?:'([a-z0-9_]+)'|\\\$[a-zA-Z_]+)/", $body, $tables);

        foreach ($tables[1] as $table) {
            $alters[] = ['table' => $table === '' ? $configuredTable : $table, 'position' => $position];
        }

        // Both foreign-key forms Laravel offers: ->constrained('parent') and ->on('parent').
        preg_match_all("/->(?:constrained|on)\('([a-z0-9_]+)'\)/i", $body, $parents);

        foreach ($parents[1] as $parent) {
            $edges[] = ['parent' => $parent, 'position' => $position];
        }
    }

    expect($createdAt)->not->toBeEmpty();

    foreach ($edges as $edge) {
        expect($createdAt)->toHaveKey($edge['parent']);

        // A self-referencing key sorts with its own file; every other parent must sort before it.
        expect($createdAt[$edge['parent']])->toBeLessThanOrEqual($edge['position']);
    }

    foreach ($alters as $alter) {
        expect($createdAt)->toHaveKey($alter['table'])
            ->and($createdAt[$alter['table']])->toBeLessThanOrEqual($alter['position']);
    }
});
