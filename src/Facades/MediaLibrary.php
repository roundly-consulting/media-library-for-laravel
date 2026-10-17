<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Facades;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;
use RoundlyConsulting\MediaLibrary\Buckets\PendingFileAdd;
use RoundlyConsulting\MediaLibrary\Handles\MediaVariants;
use RoundlyConsulting\MediaLibrary\Handles\ModelMedia;
use RoundlyConsulting\MediaLibrary\Handles\RemoteUrls;
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;
use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\MediaLibrary\Testing\DnsFake;
use RoundlyConsulting\MediaLibrary\Testing\MediaLibraryFake;
use RoundlyConsulting\PackageToolkit\Concerns\RedactsSensitiveArguments;

/**
 * @method static PendingFileAdd add(string|UploadedFile $file)
 * @method static PendingFileAdd draft(string|UploadedFile $file)
 * @method static PendingFileAdd addFromUrl(string $url)
 * @method static PendingFileAdd addFromDisk(string $path, ?string $disk = null)
 * @method static PendingFileAdd addFromString(string $contents)
 * @method static PendingFileAdd addFromBase64(string $base64)
 * @method static PendingFileAdd addFromStream(resource $stream)
 * @method static ModelMedia for(Model $model)
 * @method static MediaVariants variants(Media $media)
 * @method static RemoteUrls remote()
 * @method static Media attach(Media $media, ?Model $to = null, string $bucket = 'default')
 * @method static Media bindDraft(string $token, Model $to, string $bucket = 'default')
 * @method static Media move(Media $media, ?Model $to = null, string $bucket = 'default', ?string $disk = null)
 * @method static Media moveToDisk(Media $media, string $disk)
 * @method static Media moveVariantsToDisk(Media $media, string $disk)
 * @method static Media copy(Media $media, ?Model $to = null, string $bucket = 'default', ?string $disk = null)
 * @method static Media replace(Media $media, string|UploadedFile $file)
 * @method static void delete(Media $media)
 * @method static list<string> regenerate(Media $media, list<string> $only = [], bool $force = false)
 * @method static int pruneDrafts()
 * @method static list<string> rulesFor(class-string $modelClass, string $bucket = 'default')
 * @method static Builder<Media> bucket(string $bucket = 'default')
 * @method static Media|null find(string $uuid)
 * @method static int clearBucket(string $bucket = 'default')
 *
 * @see MediaLibraryManager
 */
final class MediaLibrary extends Facade
{
    use RedactsSensitiveArguments;

    /**
     * Swap the manager for a recording fake: nothing touches a disk, the database, the queue or
     * the event bus, and every call — through this facade, an injected manager, a `for()` handle,
     * the `InteractsWithMedia` trait or a `Media` model method — is recorded for the `assert*()`
     * methods.
     */
    public static function fake(): MediaLibraryFake
    {
        $fake = app(MediaLibraryFake::class);

        self::swap($fake);

        return $fake;
    }

    /**
     * Answer the remote URL guard's DNS lookups from a table — for `addFromUrl()`, `remote()` and
     * the `SafeRemoteUrl` rule alike — so a test never reaches real DNS: a name the table was not
     * given does not resolve. Pin more with `->resolve($host, $addresses)`; pinned addresses are
     * judged like real answers, so a private one is still refused. Only DNS is faked — answer the
     * requests with `Http::fake()`. Independent of {@see self::fake()}; a second call in the same
     * test returns the same table, and the next test starts without one.
     *
     * ```php
     * MediaLibrary::fakeDns(['docs.example' => '93.184.216.34']);
     * Http::fake(['docs.example/*' => Http::response('hello')]);
     * ```
     *
     * @param  array<string, string|list<string>>  $records  host name => its address(es); `[]` = unresolvable
     */
    public static function fakeDns(array $records = []): DnsFake
    {
        return DnsFake::install(app(), $records);
    }

    protected static function getFacadeAccessor(): string
    {
        return MediaLibraryManager::class;
    }
}
