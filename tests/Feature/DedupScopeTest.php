<?php

declare(strict_types=1);

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RoundlyConsulting\MediaLibrary\Contracts\PathGenerator;
use RoundlyConsulting\MediaLibrary\Deduplication\OwnerDeduplicationScope;
use RoundlyConsulting\MediaLibrary\Deduplication\PathPrefixDeduplicationScope;
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Support\MediaConfig;
use RoundlyConsulting\MediaLibrary\Support\MediaModel;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\SameBucketDeduplicationScope;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TenantPathGenerator;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\TestUser;
use RoundlyConsulting\PackageToolkit\Exceptions\InvalidConfigurationException;

/*
 * `media.deduplicate_scope` decides who may share a stored original. `global` (the default) is
 * the behaviour every earlier release had: identical bytes are stored once for all owners, so in a
 * multi-tenant app one tenant's row points at a file another tenant's upload wrote.
 */

function scopeUser(string $name): TestUser
{
    return TestUser::query()->create(['name' => $name]);
}

function addPixel(TestUser $owner, string $bucket = 'gallery'): Media
{
    return MediaLibrary::for($owner)->add(__DIR__.'/../files/pixel.png')->toBucket($bucket);
}

it('shares identical bytes across owners by default (global scope)', function (): void {
    $a = addPixel(scopeUser('Tenant A'));
    $b = addPixel(scopeUser('Tenant B'));

    expect(MediaConfig::deduplicationScope())->toBe(MediaConfig::DEDUPLICATION_SCOPES['global'])
        ->and($b->getPath())->toBe($a->getPath())
        ->and(Storage::disk('public')->allFiles())->toHaveCount(1);
});

it('shares only within one owner under the owner scope', function (): void {
    config()->set('media.deduplicate_scope', 'owner');

    $tenantA = scopeUser('Tenant A');
    $a1 = addPixel($tenantA);
    $a2 = addPixel($tenantA, 'covers');
    $b = addPixel(scopeUser('Tenant B'));

    expect($a2->getPath())->toBe($a1->getPath())
        ->and($b->getPath())->not->toBe($a1->getPath())
        ->and(Storage::disk('public')->allFiles())->toContain($a1->getPath(), $b->getPath());

    // Deleting tenant B's media leaves tenant A's file, and the other way round.
    MediaLibrary::delete($b);
    Storage::disk('public')->assertExists($a1->getPath());
});

it('shares global media only with global media, and never a draft, under the owner scope', function (): void {
    config()->set('media.deduplicate_scope', 'owner');

    $owned = addPixel(scopeUser('Tenant A'));
    $global = MediaLibrary::add(__DIR__.'/../files/pixel.png')->toBucket('library');
    $global2 = MediaLibrary::add(__DIR__.'/../files/pixel.png')->toBucket('library');
    $draft = MediaLibrary::draft(__DIR__.'/../files/pixel.png')->toBucket('gallery');
    $draft2 = MediaLibrary::draft(__DIR__.'/../files/pixel.png')->toBucket('gallery');

    expect($global->getPath())->not->toBe($owned->getPath())
        ->and($global2->getPath())->toBe($global->getPath())
        ->and($draft->getPath())->not->toBeIn([$owned->getPath(), $global->getPath()])
        ->and($draft2->getPath())->not->toBe($draft->getPath());

    // Nor does a later add share onto a draft once it is bound — it is that owner's file then.
    $bound = MediaLibrary::bindDraft((string) $draft->draft_token, $tenant = scopeUser('Tenant C'), 'gallery');
    $next = addPixel($tenant);

    expect($next->getPath())->toBe($bound->getPath());
});

it('shares only under the same path prefix under the path_prefix scope', function (): void {
    config()->set('media.deduplicate_scope', 'path_prefix');
    app()->bind(PathGenerator::class, TenantPathGenerator::class);

    $tenantA = scopeUser('Tenant A');
    $a1 = addPixel($tenantA);
    $a2 = addPixel($tenantA, 'covers');
    $b = addPixel(scopeUser('Tenant B'));

    expect($a1->getPath())->toStartWith("tenants/{$tenantA->getKey()}/")
        ->and($a2->getPath())->toBe($a1->getPath())
        ->and($b->getPath())->not->toBe($a1->getPath())
        ->and($b->getPath())->toStartWith("tenants/{$b->model_id}/");

    // The whole of tenant B's prefix can go without touching a file tenant A's rows point at.
    Storage::disk('public')->deleteDirectory("tenants/{$b->model_id}");
    Storage::disk('public')->assertExists($a1->getPath());
});

it('takes the part of the directory ahead of the uuid as the prefix', function (): void {
    $media = MediaModel::new();
    $media->uuid = 'abc';

    $prefixFor = static function (string $directory) use ($media): string {
        $paths = new class($directory) implements PathGenerator
        {
            public function __construct(private readonly string $directory) {}

            public function getPath(Media $media): string
            {
                return $this->directory;
            }

            public function getPathForVariants(Media $media): string
            {
                return $this->directory.'variants/';
            }
        };

        return (new PathPrefixDeduplicationScope($paths))->prefixFor($media);
    };

    expect($prefixFor('abc/'))->toBe('')
        ->and($prefixFor('tenants/42/abc/'))->toBe('tenants/42/')
        ->and($prefixFor('/tenants//42/abc/x/'))->toBe('tenants/42/')
        ->and($prefixFor('shared/files/'))->toBe('shared/files/');
});

it('shares like global under path_prefix with the default layout, but never onto a row without a stored path', function (): void {
    config()->set('media.deduplicate_scope', 'path_prefix');

    $legacy = addPixel(scopeUser('Tenant A'));
    // A row written before paths were stored: its path is derived, not queryable.
    MediaModel::query()->whereKey($legacy->getKey())->toBase()->update(['path' => null]);

    $first = addPixel(scopeUser('Tenant B'));
    $second = addPixel(scopeUser('Tenant C'));

    expect($first->getPath())->not->toBe($legacy->fresh()?->getPath())
        ->and($second->getPath())->toBe($first->getPath());
});

it('takes a scope class of the host', function (): void {
    config()->set('media.deduplicate_scope', SameBucketDeduplicationScope::class);

    $owner = scopeUser('Tenant A');
    $gallery = addPixel($owner);
    $galleryAgain = addPixel(scopeUser('Tenant B'));
    $covers = addPixel($owner, 'covers');
    $vault = MediaLibrary::for($owner)->add(__DIR__.'/../files/pixel.png')->toBucket('vault');
    $vaultAgain = MediaLibrary::for($owner)->add(__DIR__.'/../files/pixel.png')->toBucket('vault');

    expect($galleryAgain->getPath())->toBe($gallery->getPath())
        ->and($covers->getPath())->not->toBe($gallery->getPath())
        ->and($vaultAgain->getPath())->not->toBe($vault->getPath());
});

it('refuses a scope that is no DeduplicationScope', function (mixed $scope): void {
    config()->set('media.deduplicate_scope', $scope);

    expect(static fn () => MediaConfig::deduplicationScope())->toThrow(InvalidConfigurationException::class, 'media.deduplicate_scope');
})->with([
    'a typo' => 'owners',
    'another class' => TestUser::class,
    'not a string' => [['owner']],
]);

it('reads a blank scope as the default', function (): void {
    config()->set('media.deduplicate_scope', ' ');

    expect(MediaConfig::deduplicationScope())->toBe(MediaConfig::DEDUPLICATION_SCOPES['global']);
});

it('does not share a replacement across owners under the owner scope', function (): void {
    config()->set('media.deduplicate_scope', 'owner');

    $tenantA = scopeUser('Tenant A');
    $a = addPixel($tenantA);
    $b = MediaLibrary::for(scopeUser('Tenant B'))->add(__DIR__.'/../files/wide.png')->toBucket('gallery');
    $own = MediaLibrary::for($tenantA)->add(__DIR__.'/../files/wide.png')->toBucket('gallery');

    $replacedB = MediaLibrary::replace($b, __DIR__.'/../files/pixel.png');
    $replacedOwn = MediaLibrary::replace($own, __DIR__.'/../files/pixel.png');

    expect($replacedB->getPath())->not->toBe($a->getPath())
        ->and($replacedOwn->getPath())->toBe($a->getPath());
});

it('does not share a row that left the scope between the lookup and its lock', function (): void {
    config()->set('media.deduplicate_scope', 'owner');

    $tenantA = scopeUser('Tenant A');
    $canonical = addPixel($tenantA);
    $tenantB = scopeUser('Tenant B');
    $moved = false;

    // Right after the dedup lookup picked the canonical row, a concurrent request moves it to
    // tenant B (as a move() committing in between would).
    DB::listen(static function (QueryExecuted $query) use ($canonical, $tenantB, &$moved): void {
        if ($moved || ! str_starts_with(strtolower($query->sql), 'select') || ! str_contains($query->sql, 'checksum')) {
            return;
        }

        $moved = true;
        MediaModel::query()->whereKey($canonical->getKey())->toBase()->update(['model_id' => $tenantB->getKey()]);
    });

    $next = addPixel($tenantA);

    expect($moved)->toBeTrue()
        ->and($next->getPath())->not->toBe($canonical->getPath())
        ->and(Storage::disk('public')->exists($next->getPath()))->toBeTrue();
});

it('lets each owner measure its own files under the owner scope, adding up to the total', function (): void {
    $ownerUsage = static function (TestUser ...$owners): array {
        return array_map(
            static fn (TestUser $owner): int => MediaLibrary::usage(Media::query()->forModel($owner))->originalBytes,
            $owners,
        );
    };

    $bytes = filesize(__DIR__.'/../files/pixel.png');

    // Global: the shared file counts once in each tenant's usage, and once in the total.
    addPixel($a = scopeUser('Tenant A'));
    addPixel($b = scopeUser('Tenant B'));

    expect(array_sum($ownerUsage($a, $b)))->toBe(2 * $bytes)
        ->and(MediaLibrary::usage()->originalBytes)->toBe($bytes);

    // Owner: each tenant stores (and is measured for) its own copy; the parts add up.
    config()->set('media.deduplicate_scope', 'owner');
    addPixel($c = scopeUser('Tenant C'));
    addPixel($c);
    addPixel($d = scopeUser('Tenant D'));

    expect($ownerUsage($c, $d))->toBe([$bytes, $bytes])
        ->and(MediaLibrary::usage(Media::query()->whereIn('model_id', [$c->getKey(), $d->getKey()]))->originalBytes)
        ->toBe(array_sum($ownerUsage($c, $d)));
});

it('resolves the built-in scopes through the container', function (): void {
    config()->set('media.deduplicate_scope', 'owner');

    expect(app(MediaConfig::deduplicationScope()))->toBeInstanceOf(OwnerDeduplicationScope::class);
});

it('holds off a concurrent re-home of the row it shares until the sharing row commits', function (): void {
    config()->set('media.deduplicate_scope', 'owner');

    $tenantA = scopeUser('Tenant A');
    $tenantB = scopeUser('Tenant B');
    $canonical = addPixel($tenantA);

    // Moves the canonical row to tenant B from a SECOND session; false when a row lock held it
    // off past the timeout.
    $rehome = static function () use ($canonical, $tenantB): bool {
        config()->set('database.connections.rehome', config('database.connections.testing'));
        DB::purge('rehome');
        DB::connection('rehome')->statement("SET lock_timeout = '1s'");

        try {
            DB::connection('rehome')->table('media')->where('id', $canonical->getKey())->update(['model_id' => $tenantB->getKey()]);

            return true;
        } catch (QueryException) {
            return false;
        } finally {
            DB::disconnect('rehome');
        }
    };

    DB::transaction(function () use ($tenantA, $canonical, $rehome): void {
        expect(addPixel($tenantA)->getPath())->toBe($canonical->getPath())
            ->and($rehome())->toBeFalse();
    });

    expect($rehome())->toBeTrue();
})->skip(fn (): bool => config('database.connections.testing.driver') !== 'pgsql', 'needs two sessions on a real engine');
