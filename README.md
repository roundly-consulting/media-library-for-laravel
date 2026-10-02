<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/media-library-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=media-library-for-laravel">
    <img src="art/hero.png" alt="Media Library for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

<!-- roundly-badges:start -->
<p align="center">
  <a href="https://packagist.org/packages/roundly-consulting/media-library-for-laravel"><img src="https://img.shields.io/packagist/v/roundly-consulting/media-library-for-laravel?style=flat-square&label=release" alt="Latest release"></a>
  <a href="https://github.com/roundly-consulting/media-library-for-laravel/actions/workflows/run-tests.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/media-library-for-laravel/run-tests.yml?branch=main&style=flat-square&label=tests" alt="Tests"></a>
  <a href="https://github.com/roundly-consulting/media-library-for-laravel/actions/workflows/fix-php-code-style-issues.yml"><img src="https://img.shields.io/github/actions/workflow/status/roundly-consulting/media-library-for-laravel/fix-php-code-style-issues.yml?branch=main&style=flat-square&label=code%20style" alt="Code style"></a>
  <a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/donate-support%20our%20open%20source-F24E29?style=flat-square&logo=stripe&logoColor=white" alt="Donate"></a>
  <a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/patreon-become%20a%20patron-F96854?style=flat-square&logo=patreon&logoColor=white" alt="Patreon"></a>
  <a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=media-library-for-laravel#crypto"><img src="https://img.shields.io/badge/crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=flat-square&logo=bitcoin&logoColor=white" alt="Crypto"></a>
</p>
<!-- roundly-badges:end -->

# Media Library for Laravel

A native Laravel media library: attach files to your Eloquent models in named **buckets**,
or store **global** media with no owning model — all persisted in a single polymorphic
`media` table and stored through Laravel's own filesystem, on any disk you configure.

Built with only official Laravel and Symfony dependencies. No third-party media or image
vendors.

What you get:

- **Model media buckets & global media** — attach files to named buckets on any model, or store
  global media with no owner, all in one polymorphic `media` table.
- **Multi-disk** — any Laravel disk, a configurable default, and separate disks for originals and
  variants (e.g. originals on cold storage, variants on hot).
- **Image variants** — native, image-only derivatives via `ext-imagick` (with a `ext-gd`
  fallback); sync by default, queue opt-in.
- **Private URLs, streaming & downloads** — one `temporaryUrl()` API that presigns natively or
  falls back to a signed streaming route.
- **Deduplication & integrity** — content-addressable storage with refcount-guarded delete/move
  and checksum verification.
- **Modern image DX** — ThumbHash + Blurhash LQIP placeholders and responsive `srcset` helpers.
- **Upload ergonomics** — draft media, bucket-derived validation rules, replace-in-place, and
  attach-existing-by-reference.
- **CDN-ready** — a pluggable URL generator that rewrites public URLs onto a CDN with cache-busting.

## Requirements

- PHP 8.4+
- Laravel 12 or 13
- `ext-imagick` (preferred) or `ext-gd` — only needed to generate image variants; storing
  media without variants requires neither

## Installation

```bash
composer require roundly-consulting/media-library-for-laravel
```

Publish and run the migration. The migration is **publish-only** — the package never loads it, so
a bare `php artisan migrate` will not create the `media` table until you have published it:

```bash
php artisan vendor:publish --tag="media-migrations"
php artisan migrate
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag="media-config"
```

Publishing the signed streaming route file is optional too — the package registers it for you
(unless `media.stream.enabled` is `false`); publish it only if you want to edit it:

```bash
php artisan vendor:publish --tag="media-routes"
```

The publish tags the package exposes are `media-migrations`, `media-config` and `media-routes`.

## Configuration

The package works with **zero** host configuration — every key has an env-backed default. Publish
`config/media.php` only to override. The full file:

```php
return [
    'disk' => env('MEDIA_DISK', 'public'),
    'variants_disk' => env('MEDIA_VARIANTS_DISK'),

    'media_model' => RoundlyConsulting\MediaLibrary\Models\Media::class,
    'table_name' => 'media',

    'queue_variants_by_default' => false,
    'queue_connection' => env('MEDIA_QUEUE_CONNECTION'),
    'queue_name' => env('MEDIA_QUEUE'),

    'image_driver' => env('MEDIA_IMAGE_DRIVER', 'imagick'),
    'variant' => [
        'quality' => 75,
        'background' => '#ffffff',
    ],

    'url_fallback_to_original' => false,
    'temporary_url_default_lifetime' => 5,

    'stream' => [
        'enabled' => filter_var(env('MEDIA_STREAM_ENABLED', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true,
        'route_prefix' => 'media',
        'middleware' => ['web'],
    ],

    'path_generator' => RoundlyConsulting\MediaLibrary\Support\DefaultPathGenerator::class,
    'file_namer' => RoundlyConsulting\MediaLibrary\Support\DefaultFileNamer::class,

    'default_visibility' => 'public',
    'max_file_size' => 1024 * 1024 * 256,

    'remote' => [
        'headers' => [],
        'timeout' => 30,
    ],

    'deduplicate' => true,
    'checksum_algorithm' => 'sha256',
    'verify_checksum_on_read' => false,

    'placeholders' => [
        'thumbhash' => true,
        'blurhash' => true,
    ],

    'responsive' => [
        'widths' => [320, 640, 960, 1280, 1920],
    ],

    'drafts' => [
        'ttl' => 1440,
    ],

    'url_generator' => RoundlyConsulting\MediaLibrary\Support\DefaultUrlGenerator::class,
    'cdn' => [
        'enabled' => false,
        'base_url' => env('MEDIA_CDN_URL'),
        'cache_bust' => true,
        'disks' => [],
    ],
];
```

Every key:

| Key | Type | Default | Env | Purpose |
|---|---|---|---|---|
| `disk` | `string` | `public` | `MEDIA_DISK` | Default disk for **originals** when a bucket/add doesn't specify one. |
| `variants_disk` | `?string` | `null` | `MEDIA_VARIANTS_DISK` | Default disk for **variants**. `null` means the same disk as the original. |
| `media_model` | `class-string<Media>` | `Media::class` | — | Eloquent model used to persist media. Swap for a subclass to extend it. |
| `table_name` | `string` | `media` | — | Database table the media model uses. |
| `queue_variants_by_default` | `bool` | `false` | — | Queue all variant generation by default (otherwise sync). |
| `queue_connection` | `?string` | `null` | `MEDIA_QUEUE_CONNECTION` | Queue connection for `GenerateVariantsJob`. `null` = default connection. |
| `queue_name` | `?string` | `null` | `MEDIA_QUEUE` | Queue name for `GenerateVariantsJob`. `null` = default queue. |
| `image_driver` | `string` | `imagick` | `MEDIA_IMAGE_DRIVER` | `imagick` or `gd`. Auto-falls back to `gd` when Imagick is absent. |
| `variant.quality` | `int` | `75` | — | Default JPEG/WebP quality (1–100) for generated variants. |
| `variant.background` | `string` | `#ffffff` | — | Flatten color when a transparent image is converted to JPEG. |
| `url_fallback_to_original` | `bool` | `false` | — | When `getUrl()` is asked for an un-generated variant: throw (`false`) or return the original's URL (`true`). |
| `temporary_url_default_lifetime` | `int` | `5` | — | Default lifetime (minutes) for temporary/signed URLs when no expiry is passed. |
| `stream.enabled` | `bool` | `true` | `MEDIA_STREAM_ENABLED` | Register the signed streaming route. The env value is read as a boolean (`false`/`0`/`off`/`no` turn it off). |
| `stream.route_prefix` | `string` | `media` | — | URI prefix for the streaming route. |
| `stream.middleware` | `list<string>` | `['web']` | — | Middleware stack for the streaming route. Laravel's `signed` is always appended. |
| `path_generator` | `class-string<PathGenerator>` | `DefaultPathGenerator::class` | — | Directory layout for a media's files. |
| `file_namer` | `class-string<FileNamer>` | `DefaultFileNamer::class` | — | Original and variant file naming. |
| `default_visibility` | `string` | `public` | — | `public` or `private` for new media when a bucket/add doesn't set it. |
| `max_file_size` | `?int` | `268435456` | — | Package-level size cap (bytes): enforced on every add (and on a remote download) and emitted by the derived validation rules. A bucket's own `maxFileSize()` overrides it; `null` = no limit. |
| `remote.headers` | `array<string,string>` | `[]` | — | Extra HTTP headers for `addMediaFromUrl()`. |
| `remote.timeout` | `int` | `30` | — | HTTP timeout (seconds) for `addMediaFromUrl()`. |
| `deduplicate` | `bool` | `true` | — | Reuse storage for identical bytes on the same `(disk, visibility)`. |
| `checksum_algorithm` | `string` | `sha256` | — | Hash for the content checksum (dedup key + integrity baseline). One of `sha256`, `sha384`, `sha512`, `sha512/256`, `sha3-256`, `sha3-384`, `sha3-512`; anything else (md5, sha1, crc32…) throws `InvalidConfigurationException`, because a collision-prone dedup key would let one upload take over another's file. |
| `verify_checksum_on_read` | `bool` | `false` | — | Re-hash the original on stream/download; throws `ChecksumMismatch` on drift. |
| `placeholders.thumbhash` | `bool` | `true` | — | Compute a ThumbHash LQIP on add for images. |
| `placeholders.blurhash` | `bool` | `true` | — | Compute a Blurhash LQIP on add for images. |
| `responsive.widths` | `list<int>` | `[320, 640, 960, 1280, 1920]` | — | Default responsive `srcset` ladder, overridable per bucket. |
| `drafts.ttl` | `int` | `1440` | — | Minutes before an unbound draft is prunable (default 24h). |
| `url_generator` | `class-string<UrlGenerator>` | `DefaultUrlGenerator::class` | — | URL building strategy. Takes precedence over the CDN generator. |
| `cdn.enabled` | `bool` | `false` | — | Rewrite public URLs onto a CDN host. |
| `cdn.base_url` | `?string` | `null` | `MEDIA_CDN_URL` | CDN base URL, e.g. `https://cdn.example.com`. |
| `cdn.cache_bust` | `bool` | `true` | — | Append `?v={updated_at}` to public URLs so replaced media busts caches. |
| `cdn.disks` | `list<string>` | `[]` | — | Limit CDN rewriting to these disks. `[]` = all public disks. |

Every `bool` switch is parsed as a boolean wherever it is read: `true`/`1`/`on`/`yes` turn it on
and `false`/`0`/`off`/`no` turn it off, so a switch you feed from `.env` in your published config
behaves as written.

## Quick start

Add the `HasMedia` contract and the `InteractsWithMedia` trait to any model, and declare its
buckets in `registerMediaBuckets()`. Buckets can pin originals and variants to different disks
(e.g. cold originals, hot variants) and declare image variants inline:

```php
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\MediaLibrary\Concerns\InteractsWithMedia;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;
use RoundlyConsulting\MediaLibrary\Variants\VariantRegistrar;

final class User extends Model implements HasMedia
{
    use InteractsWithMedia;

    public function registerMediaBuckets(): void
    {
        $this->addMediaBucket('avatar')
            ->singleFile()                                   // replaces the previous file on add
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->useDisk('cold')                                // originals on cold storage
            ->storingVariantsOnDisk('hot')                   // derivatives on hot storage
            ->private()                                      // visibility: private -> signed URLs
            ->useFallbackUrl('https://example.com/avatar.png')
            ->registerVariants(function (VariantRegistrar $v): void {
                $v->add('thumb')->fit('crop')->width(120)->height(120)->format('webp');
                $v->add('display')->width(800)->format('webp')->quality(80)->queued();
            });

        $this->addMediaBucket('gallery')->useDisk('public'); // simple public bucket
    }
}
```

### Adding media

Everything goes through the `MediaLibrary` facade. `MediaLibrary::for($model)` scopes adds and
reads to one owner; every `add*` method returns a `PendingFileAdd` builder, and the terminal
`toBucket()` writes the file and returns the `Media`:

```php
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;

$media = MediaLibrary::for($user);

$media->add($request->file('avatar'))->toBucket('avatar');            // UploadedFile or local path
$media->addFromRequest('avatar')->toBucket('avatar');                 // request upload by key
$media->addFromUrl('https://example.com/poster.png')->toBucket('gallery');
$media->addFromDisk('incoming/doc.pdf', 's3')->toBucket('gallery');
$media->addFromString($bytes)->usingFileName('note.txt')->toBucket('gallery');
$media->addFromBase64($base64)->toBucket('gallery');
$media->addFromStream($resource)->usingFileName('upload.bin')->toBucket('gallery');
```

The `InteractsWithMedia` trait offers the same adds on the model itself — they go through the
facade's manager, so everything below (including `MediaLibrary::fake()`) applies to them too:

```php
$user->addMedia($request->file('avatar'))->toMediaBucket('avatar');
$user->addMediaFromRequest('avatar')->toMediaBucket('avatar');
$user->addMultipleMediaFromRequest(['a', 'b']);                       // PendingFileAdd[] (keys without a file skipped)
$user->addMediaFromUrl($url);  $user->addMediaFromDisk($path, 's3');
$user->addMediaFromString($bytes);  $user->addMediaFromBase64($b64);  $user->addMediaFromStream($resource);
```

### The `PendingFileAdd` builder

```php
$media = MediaLibrary::for($user)->add($request->file('avatar'))
    ->usingName('Profile photo')                 // display name
    ->usingFileName('avatar.jpg')                // stored filename (made safe, see below)
    ->withCustomProperties(['alt' => 'Jane'])    // arbitrary metadata
    ->withProperty('source', 'signup')           // one property at a time
    ->useDisk('cold')                            // override the bucket/config original disk
    ->storingVariantsOnDisk('hot')               // override the bucket/config variants disk
    ->withVisibility('private')                  // override the bucket default
    ->onQueue('media')                           // queue any queued variants on this queue
    ->asDraft()                                  // store as an unbound draft (see Drafts)
    ->toBucket('avatar');                        // terminal: returns Media (`toMediaBucket()` is the same)
```

The terminal call validates the file before anything is written — the bucket's mime allowlist,
its size cap (`maxFileSize()`, else `media.max_file_size`) and, for images, its
`minDimensions()`/`maxDimensions()` — and throws a typed exception on failure:
`FileUnacceptableForBucket`, `FileDoesNotExist`, `DiskDoesNotExist`, or `RemoteFileRejected`
(`addFromUrl()` with a non-http(s) URL or a body over the size cap). These are the same limits
`MediaLibrary::rulesFor()` derives, so a FormRequest and the package never disagree.

A source is only ever read: a local path you add is never moved or deleted — the package always
stores a copy.

### Upload safety

Every name and type that reaches storage is treated as untrusted:

- **The mime type is sniffed from the bytes** (`ext-fileinfo`) — never taken from the client, a
  remote `Content-Type` header, or a disk's stored metadata.
- **The stored name is one safe path segment.** Directory parts, separators and control
  characters are stripped from a client's name and from `usingFileName()`, so a name can never
  reach outside the media's own directory.
- **The extension cannot lie about active content.** An extension a web server may execute
  (`.php`, `.phtml`, `.shtml`, …) is never stored, and one a browser runs (`.html`, `.svg`,
  `.js`, …) is kept only when the bytes really are that type — a PNG uploaded as `x.html` is
  stored as `x.png`. Truthful or harmless extensions (`IMG_0001.JPG`, `data.csv`) are kept.
- **`addFromUrl()`** only fetches `http`/`https` URLs, honours `media.remote.timeout`, and refuses
  a body over the size cap. It fetches whatever host it is given: when the URL comes from a user,
  check the host against an allowlist first — otherwise it can be pointed at your internal
  network (SSRF).
- **A bucket without `acceptsMimeTypes()` accepts any type**, HTML and SVG included. Give every
  bucket that holds user uploads an allowlist.

### Reading and deleting

```php
$media = MediaLibrary::for($user);

$media->get('avatar');                           // Collection<Media>, ordered
$media->first('avatar');                         // ?Media
$media->has('avatar');                           // bool
$media->find($uuid);                             // ?Media — only if this user owns it
$media->url('avatar');                           // first URL, the bucket fallback, or ''
$media->url('avatar', 'thumb');                  // a variant's URL
$media->temporaryUrl('avatar', expiry: now()->addMinutes(10));
$media->clear('avatar');                         // delete every media in the bucket; returns the count
$media->delete($photo);                          // throws MediaDoesNotBelongToModel for someone else's media
```

The scope is a security boundary: `find()` returns null and `delete()` throws
`MediaDoesNotBelongToModel` for media that is global or belongs to another model, so a controller
can safely resolve `MediaLibrary::for($request->user())->find($uuid)`.

The trait mirrors the readers: `getMedia()`, `getFirstMedia()`, `getFirstMediaUrl()`,
`getFirstTemporaryUrl()`, `hasMedia()` and `clearMediaBucket()`.

### Global media (no owner)

The flat `add*` methods store media with no owning model:

```php
$logo = MediaLibrary::add(storage_path('brand/logo.svg'))
    ->usingName('Primary logo')
    ->toBucket('brand');

MediaLibrary::bucket('brand')->get();            // Builder<Media> over a global bucket (unbound drafts excluded)
MediaLibrary::find($logo->uuid);                 // any media by uuid
MediaLibrary::clearBucket('brand');              // delete the global bucket; returns the count
```

### The whole facade

| Method | Returns | Does |
|---|---|---|
| `add($file)`, `addFromUrl()`, `addFromDisk()`, `addFromString()`, `addFromBase64()`, `addFromStream()` | `PendingFileAdd` | Start a global add. |
| `draft($file)` | `PendingFileAdd` | Start a global draft add (see Drafts). |
| `for($model)` | `ModelMedia` | Owner-scoped `add*`, `bindDraft()`, `attach()`, `get()`, `first()`, `has()`, `find()`, `url()`, `temporaryUrl()`, `clear()`, `delete()`. |
| `attach($media, to: ?Model, bucket:)` | `Media` | Attach by reference (zero bytes copied); `to: null` = a global bucket. |
| `bindDraft($token, to: $model, bucket:)` | `Media` | Bind a draft to an owner — the bucket's rules, storage, single-file rule and variants apply (see Drafts). |
| `move($media, to: ?Model, bucket:, disk:)` | `Media` | Re-home and/or move across disks. |
| `moveToDisk($media, $disk)` / `moveVariantsToDisk($media, $disk)` | `Media` | Move the files, or only the variants, to another disk. |
| `copy($media, to: ?Model, bucket:, disk:)` | `Media` | Duplicate into a new row (fresh uuid). |
| `replace($media, $file)` | `Media` | New bytes, same id/uuid (and URL, unless the old file is shared). |
| `delete($media)` | `void` | Delete the row and its files (refcount-guarded). |
| `variants($media)` | `MediaVariants` | `all()`, `generated()`, `missing()`, `regenerate(only:, force:)`. |
| `regenerate($media, only: [], force: false)` | `list<string>` | Re-render variants; returns the names rendered. |
| `pruneDrafts()` | `int` | Delete expired, never-bound drafts. |
| `rulesFor(Model::class, $bucket)` | `list<string>` | Validation rules from a bucket definition. |
| `bucket($bucket)` / `find($uuid)` / `clearBucket($bucket)` | `Builder` / `?Media` / `int` | Global reads and clear. |

The `Media` model's own `move()`, `copy()`, `moveToDisk()`, `moveVariantsToDisk()`, `replace()`
and `deleteWithFiles()` are shorthands for the flat verbs above.

### Without the facade

Inject `MediaLibraryManager` — the facade's root — and call the same API:

```php
use RoundlyConsulting\MediaLibrary\MediaLibraryManager;

final class AvatarController
{
    public function __construct(private MediaLibraryManager $media) {}

    public function store(Request $request): Media
    {
        return $this->media->for($request->user())->addFromRequest('avatar')->toBucket('avatar');
    }
}
```

Or resolve the action behind a verb directly:

```php
use RoundlyConsulting\MediaLibrary\Actions\MoveMediaAction;
use RoundlyConsulting\MediaLibrary\Actions\RegenerateVariantsAction;

app(MoveMediaAction::class)->execute($media, $otherUser, 'gallery', 'cold');
app(RegenerateVariantsAction::class)->execute($media, only: ['thumb'], force: true);
```

The host-facing actions are `AddMediaAction`, `AttachMediaAction`, `BindDraftMediaAction`,
`MoveMediaAction`, `MoveMediaVariantsAction`, `CopyMediaAction`, `ReplaceMediaAction`,
`DeleteMediaAction`, `RegenerateVariantsAction` and `PruneDraftsAction`. `GenerateVariantsAction`
is an internal building block (it renders exactly the definitions it is handed). Calling an
action directly bypasses `MediaLibrary::fake()`.

### Testing your app with `MediaLibrary::fake()`

```php
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;

$fake = MediaLibrary::fake();

$this->post('/profile/avatar', ['avatar' => UploadedFile::fake()->image('me.png')]);

$fake->assertAdded('avatar', to: $user);
$fake->assertNothingDeleted();
```

The fake writes nothing — no file on any disk, no row, no queued job, no event — so a test needs
neither real disks nor `Storage::fake()`. It records every call, whether it came through the
facade, an injected `MediaLibraryManager`, a `for()` handle, the `InteractsWithMedia` trait or a
`Media` model method. Mutations still return something realistic: adds, attaches and copies return
an unsaved `Media` with a fresh uuid, owner, bucket, disk, visibility and the same safe file name a
real add stores; moves re-point the media in memory. Bucket acceptance rules (mime allowlist, size
cap, image dimensions) still apply to adds and draft binds, and an unknown or expired draft token
still throws. Reads run against the database as usual.

| Assert | Passes when |
|---|---|
| `assertAdded(?bucket, ?to)` / `assertNothingAdded()` | Media was (not) added. |
| `assertAttached(?media, ?to, ?bucket)` / `assertNothingAttached()` | Media was (not) attached by reference. |
| `assertDraftBound(?token, ?to, ?bucket)` / `assertNothingBound()` | A draft was (not) bound. |
| `assertMoved(?media, ?to, ?bucket, ?disk)` / `assertNothingMoved()` | `move()` / `moveToDisk()` was (not) called. |
| `assertVariantsMoved(?media, ?disk)` / `assertNoVariantsMoved()` | `moveVariantsToDisk()` was (not) called. |
| `assertCopied(?media, ?to, ?bucket, ?disk)` / `assertNothingCopied()` | Media was (not) copied. |
| `assertReplaced(?media)` / `assertNothingReplaced()` | Media was (not) replaced. |
| `assertDeleted(?media)` / `assertNothingDeleted()` | Media was (not) deleted — `clear()` records one delete per media. |
| `assertRegenerated(?media, ?only, ?force)` / `assertNothingRegenerated()` | Variants were (not) regenerated. |
| `assertDraftsPruned()` / `assertDraftsNotPruned()` | `pruneDrafts()` / `media:prune-drafts` did (not) run. |

Every argument is an optional filter; `null` matches anything.

## Image variants

Declare resized/reformatted image derivatives ("variants") inline on a bucket. They are
generated automatically whenever image media is added to that bucket.

```php
use RoundlyConsulting\MediaLibrary\Variants\VariantRegistrar;

public function registerMediaBuckets(): void
{
    $this->addMediaBucket('photos')
        ->useDisk('public')
        ->storingVariantsOnDisk('cdn')               // variants can live on a different disk
        ->registerVariants(function (VariantRegistrar $v): void {
            $v->add('thumb')->fit('crop')->width(120)->height(120)->format('webp');
            $v->add('display')->width(800)->format('webp')->quality(80)->queued();
        });
}
```

Each variant supports:

- **Sizing** — `width()`, `height()`, and `fit()` with modes `contain`, `cover`/`crop`,
  `fill` (padded with `background()`), and `stretch`. With only one dimension the image scales
  keeping its aspect ratio.
- **Format** — `format()` accepts `jpg`/`jpeg`, `png`, `webp`, `avif`, `gif` (subject to the
  active driver's support; otherwise an `InvalidVariant` is thrown).
- **Quality / look** — `quality(1..100)`, `background('#ffffff')`, `sharpen(int)`.
- **Storage** — `storeOnDisk()` for a per-variant disk override.
- **Execution** — `queued()` / `nonQueued()` per variant.

Read a variant's URL or path:

```php
$media = $user->getFirstMedia('photos');

$media->getUrl('thumb');             // public URL of the generated variant
$media->getPath('display');          // path on the variants disk
$media->diskFor('thumb');            // the disk the variant was written to
$media->hasGeneratedVariant('thumb');
```

Each generated variant is recorded in `generated_variants` with the file name, format and disk it
was actually written to — e.g. `['thumb' => ['file_name' => 'thumb.webp', 'format' => 'webp',
'disk' => 'hot']]` — and every URL, path, stream, move, copy and delete resolves that record. A
variant without its own `format()` inherits the original's (`IMG_0001.JPG` → `keepformat.jpg`;
a format the drivers cannot write, such as `.bmp`, becomes `jpg`).

`getUrl()` only knows the variants a bucket declares: asking for a name the bucket never defined
(or one not generated yet) throws `InvalidVariant` unless `media.url_fallback_to_original` is on.
Guard optional names with `hasGeneratedVariant()`.

### Drivers

Variants use `ext-imagick` by default and automatically fall back to `ext-gd`. Choose the
driver explicitly with `config('media.image_driver')` (`imagick` | `gd`). When neither
extension is installed and a variant is requested, a `VariantDriverUnavailable` exception is
thrown.

Both drivers **auto-orient** photos: a phone picture stored sideways with an EXIF `Orientation`
tag is turned upright before it is resized, so variants and placeholders come out the way the
photo was taken, and no leftover flag makes a browser turn it again. The tag is read natively —
`ext-exif` is not required.

To use another engine, bind your own `RoundlyConsulting\MediaLibrary\Contracts\ImageDriver` in a
service provider; variants and placeholders resolve the driver from the container.

### Sync vs. queued

Variants are generated **synchronously** by default. Opt into the queue per variant
(`->queued()`), per add (`$user->addMedia($file)->onQueue('media')->toMediaBucket('photos')`),
or globally (`config('media.queue_variants_by_default')`). Queued variants are processed by the
`GenerateVariantsJob`, which carries only the media id and variant names.

### Variants disk resolution

The disk a variant is stored on is resolved by precedence: the variant's `storeOnDisk()`, then
the add's `storingVariantsOnDisk()`, then the bucket's `storingVariantsOnDisk()`, then
`config('media.variants_disk')`, falling back to the media's own disk. The disk each variant
landed on is recorded, so `getUrl()`, `diskFor()` and deletes follow a per-variant `storeOnDisk()`.

### Events

`VariantHasBeenGenerated` fires per variant and `VariantsHaveBeenGenerated` once all of an
add's variants are done — listen for either to react to generated derivatives.

### Inspecting and regenerating variants

```php
$variants = MediaLibrary::variants($media);

$variants->all();                                   // list<Variant> — every definition that applies
$variants->generated();                             // ['thumb']
$variants->missing();                               // ['display']
$variants->regenerate();                            // render the missing ones → ['display']
$variants->regenerate(only: ['thumb'], force: true); // re-render thumb even though it exists
```

`MediaLibrary::regenerate($media, only:, force:)` is the same verb flat, and `media:regenerate`
runs it over many media. Global media has no owning bucket, so it has no variants.

### Un-generated variants

Requesting `getUrl('thumb')` for a variant that hasn't been generated throws `InvalidVariant`
by default. Set `config('media.url_fallback_to_original')` to `true` to return the original's
URL instead.

## Placeholders & responsive images

### Dimensions & LQIP placeholders

When an image is added, the package records its pixel `width`/`height` and computes two
low-quality image placeholders (LQIP) from a downscaled copy: a **ThumbHash** and a
**Blurhash**. Both encoders are pure-PHP ports validated against the published reference
vectors — no third-party dependency. Computation is synchronous and is skipped for non-image
media.

```php
$media->width;                 // e.g. 1920 (null for non-images) — as displayed, after EXIF orientation
$media->height;                // e.g. 1080

$media->placeholder();         // ['thumbhash' => '1QcSHQ…', 'blurhash' => 'LPDI|4…']
$media->thumbhash();           // string|null
$media->blurhash();            // string|null
$media->placeholderDataUri();  // 'data:image/png;base64,…' — a tiny decoded blur-up image
```

`placeholderDataUri()` decodes a placeholder server-side (ThumbHash preferred, Blurhash as a
fallback) into a tiny PNG `data:` URI you can use as a blur-up background while the full image
loads. Each placeholder type is independently toggleable:

```php
'placeholders' => [
    'thumbhash' => true,
    'blurhash'  => true,
],
```

### Responsive images (`srcset`)

Opt a bucket into responsive images with `->responsiveWidths()`. The package generates one
variant per width through the same variant engine — honouring the sync/queue rules and the
variants disk — skipping any width larger than the original (it never upscales):

```php
$this->addMediaBucket('hero')
    ->useDisk('public')
    ->responsiveWidths([480, 960, 1440, 1920]) // omit the argument for the config default ladder
    ->responsiveFormat('webp');                // optional — defaults to the original's format
```

The default ladder comes from `config('media.responsive.widths')`
(`[320, 640, 960, 1280, 1920]`). Two model accessors expose the generated widths:

```php
$media->srcset();
// "…/responsive-480.webp 480w, …/responsive-960.webp 960w, …" (ascending, only generated widths)

$media->responsiveImage('', ['alt' => 'Hero', 'sizes' => '100vw', 'class' => 'rounded']);
// <img src="…smallest width…" srcset="…" sizes="100vw" alt="Hero" class="rounded"
//      style="background-size:cover;background-image:url('data:image/png;base64,…')">
```

`responsiveImage()` emits a full `<img>` tag with the smallest generated width as the `src`
fallback, the `srcset`, optional `sizes`/`alt`/`class`, and the LQIP placeholder as an inline
blur-up background. Requesting it for non-image media throws `MediaIsNotAnImage`.
`media:regenerate` rebuilds responsive widths and `media:clean` removes orphaned width files.

## URLs, streaming & downloads

### Public URLs

Public media exposes a direct disk URL:

```php
$media->getUrl();          // original
$media->getUrl('thumb');   // a named variant (on the variants disk)

$user->getFirstMediaUrl('avatar');          // first media in a bucket, or its fallback URL / ''
$user->getFirstMediaUrl('avatar', 'thumb'); // first media's variant URL
```

Calling `getUrl()` on **private** media throws — private media has no public URL. Use a
temporary URL instead.

### Temporary URLs for private media

`getTemporaryUrl()` returns a time-limited URL using one API regardless of disk:

- If the disk supports native presigning (S3 and any driver implementing `temporaryUrl()`),
  it returns the disk's presigned URL — traffic goes straight to the cloud.
- Otherwise (local/cold disks that can't presign) it returns a Laravel **signed streaming
  route** URL served by the package's controller.

```php
use Carbon\CarbonImmutable;

$media->getTemporaryUrl(CarbonImmutable::now()->addMinutes(10));
$media->getTemporaryUrl(CarbonImmutable::now()->addMinutes(10), 'thumb');

// Trait helper — defaults the lifetime to config('media.temporary_url_default_lifetime'):
$user->getFirstTemporaryUrl('avatar');
$user->getFirstTemporaryUrl('avatar', 'thumb', CarbonImmutable::now()->addHour());
```

### The streaming route

When `config('media.stream.enabled')` is `true` (the default), the package registers a signed
route — `GET {prefix}/{media}/{variant?}` named `media.stream`. The `{media}` segment binds by
**UUID**, the route runs the `config('media.stream.middleware')` stack **plus** Laravel's
`signed` middleware, and the controller streams the file inline (range-aware) or as an
attachment when `?download=1` is in the signed URL. The route never hands out anything without a
valid signature (or, on presigning disks, a native presigned URL).

Only types a browser renders without running anything — raster images, audio, video, PDF and plain
text — are served inline. HTML, SVG, XML and every other type are sent as an **attachment**, and
every response carries `X-Content-Type-Options: nosniff` and a sandboxing
`Content-Security-Policy`, so a stored file can never run script on your application's origin. A
variant is served with its own format's type. An unparseable or multi-range `Range` header is
ignored (the whole file, `200`); a range past the end of the file gets `416`.

> **Private media needs a private disk.** `->private()` / `withVisibility('private')` sets the
> file's visibility — an S3 object ACL, or file mode `0600` on a local disk. It does not stop a web
> server that serves the disk directly: the default `public` disk is symlinked under
> `public/storage`, so a private file stored there can still be fetched from
> `/storage/{uuid}/{file}` wherever the web server runs as the PHP user — and the uuid appears in
> every signed URL. Keep private buckets on a disk the web server does not serve, such as Laravel's
> `local` disk (`storage/app/private`) or a private S3 bucket: `->useDisk('local')->private()`.

### Streaming from your own controller

The model can build the responses directly, so you can stream from a controller you already
own without the package route:

```php
public function show(Request $request, Media $media): StreamedResponse
{
    return $media->toResponse($request);            // inline (safe types), range-aware
}

public function download(Media $media): StreamedResponse
{
    return $media->toDownloadResponse('invoice.pdf'); // attachment
}
```

### Relevant configuration

```php
'temporary_url_default_lifetime' => 5,   // minutes, when no expiry is passed

'stream' => [
    'enabled'      => true,
    'route_prefix' => 'media',
    'middleware'   => ['web'],            // 'signed' is always appended by the package
],
```

## Moving & copying media

Media (and its variants) can be relocated across disks, and re-homed to a different model and
bucket — including model → global and global → model. Moves stream `readStream()` →
`writeStream()` across disks (same-disk moves use the filesystem's native `move()`), and the
database update runs in a transaction with the source files removed only after it commits, so a
rollback never orphans the row from its files.

```php
// Move the original (and same-disk variants) to another disk.
MediaLibrary::moveToDisk($media, 'cold');

// Move only the variant files to another disk; the original stays put.
MediaLibrary::moveVariantsToDisk($media, 'hot');

// Re-home to a different model and bucket (deletes the source files).
MediaLibrary::move($media, to: $otherUser, bucket: 'gallery');

// Move a model's media to global storage (no owner).
MediaLibrary::move($media, to: null, bucket: 'brand');

// Copy instead of move: a new row with a fresh UUID, source preserved.
$copy = MediaLibrary::copy($media, to: $otherUser, bucket: 'gallery', disk: 'cold');

// The same verbs on the model:
$media->moveToDisk('cold');
$media->move($otherUser, 'gallery');
$copy = $media->copy($otherUser, 'gallery', 'cold');
```

Moving or copying into a different owner or bucket applies the target bucket: its acceptance
rules are checked first (`FileUnacceptableForBucket`), its single-file rule is enforced, variants it
does not define are dropped (a copy simply doesn't carry them) and the variants it defines but the
media lacks are generated. Variants it also defines are kept — run
`MediaLibrary::regenerate($media, force: true)` if the two buckets define them differently. A
move keeps the media's disk and visibility unless you pass `disk:`.

On the same disk a copy points at the source's stored original (zero bytes copied, refcount-guarded
like a deduplicated upload); on another disk the original is copied. On the target disk the
original keeps its relative path unless another row already uses that path there.

Both fire events — `MediaHasBeenMoved` after a move, and `MediaHasBeenAdded` for the new row a
copy creates.

To permanently delete a media with its files, use `MediaLibrary::delete($media)` (or
`$media->deleteWithFiles()`). A plain Eloquent `delete()` is a soft delete and **keeps** the files
so a restore stays lossless; only a force delete (or the delete verb) removes them, which fires
`MediaHasBeenDeleted`.

## Deduplication & integrity

On every add the package records a content **checksum** (`sha256` by default, via
`media.checksum_algorithm`) and stores the original's path on the row. With deduplication on
(`media.deduplicate`, default `true`), two adds whose bytes are identical on the **same disk and
visibility** share a single physical original — the bytes are written once and both rows resolve
to the same file. A different disk or visibility is a genuinely different storage location, so it
is stored separately by design. Variants are never shared: each media generates its own variants
under its own UUID directory. `attach()` and a same-disk `copy()` share an original the same way.

Sharing is made safe by a **reference count on the stored file**: a physical original is removed —
by a delete, a cross-disk move or a replace — only when no other row, **soft-deleted rows
included**, still points at the same file (`disk` + `path`). So a soft delete followed by a
restore stays lossless even while the file is shared. A still-shared original is never
overwritten either: replacing one sharer writes its new bytes to a path of its own (see Replace
in place). Directories are only tidied away once nothing another row points at is left in them.

```php
// Verify a single media's stored original against its recorded checksum.
$media->verifyIntegrity();   // bool — false on drift or a missing file
```

Set `media.verify_checksum_on_read` to `true` to re-hash the original whenever it is streamed or
downloaded; a drifted file throws `RoundlyConsulting\MediaLibrary\Exceptions\ChecksumMismatch`.

## CDN URLs

Point public URLs at a CDN without touching your code. Enable `media.cdn` and set a base URL:

```php
'cdn' => [
    'enabled'    => true,
    'base_url'   => env('MEDIA_CDN_URL'),  // https://cdn.example.com
    'cache_bust' => true,                  // append ?v={updated_at} so replaced media busts caches
    'disks'      => [],                    // limit rewriting to these disks ([] = all public)
],
```

Only **public** URLs are rewritten onto the CDN host; private/temporary URLs stay
signed/presigned. A custom `media.url_generator` always takes precedence over the CDN generator.

## Draft (temporary) media

Upload a file **before** the owning model exists, then bind it once the model is saved. A draft is
an ordinary media row with no owner, a generated `draft_token`, and a `draft_expires_at` TTL
(default 24h, `media.drafts.ttl`). Dimensions and placeholders are computed on upload.

The bucket is only known when you bind, so binding is where it applies: its acceptance rules are
checked (`FileUnacceptableForBucket` leaves the draft unbound), the original moves to the disk
and visibility the bucket declares (a private bucket's draft stops being public), a
`singleFile()` bucket's previous media is deleted, and the bucket's variants are generated.
Unbound drafts are not global media: `MediaLibrary::bucket()`, `clearBucket()` and
`media:clear ""` leave them alone, and the model's `$hidden` keeps `draft_token` out of
serialized output (read it as a property, as below).

```php
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;

// Upload step — no model yet. Hand the token back to the client.
$draft = MediaLibrary::draft($request->file('avatar'))->toBucket('avatar');
$token = $draft->draft_token;

// When the form is submitted and the model is saved, bind by token:
MediaLibrary::for($user)->bindDraft($token, 'avatar');   // sets the owner, clears the token
// …or the flat verb, or the trait shorthand:
MediaLibrary::bindDraft($token, to: $user, bucket: 'avatar');
$user->attachDraftMedia($token, 'avatar');
```

Binding throws `DraftMediaNotFound` (unknown / already-bound token) or `DraftMediaExpired` (past
TTL). Delete expired, never-bound drafts with `MediaLibrary::pruneDrafts()` (returns the count)
or schedule `media:prune-drafts`.

`RoundlyConsulting\MediaLibrary\Events\DraftMediaHasBeenBound` fires after a successful bind.

## Validation rules from a bucket

Declare a bucket's constraints once, then reuse them in any FormRequest — change the bucket and the
rules follow.

```php
$this->addMediaBucket('avatar')
    ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
    ->maxFileSize(5 * 1024 * 1024)   // bytes
    ->minDimensions(100, 100)
    ->maxDimensions(4096, 4096);

// In a FormRequest:
public function rules(): array
{
    return [
        'avatar' => MediaLibrary::rulesFor(User::class, 'avatar'),
        // ['file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120',
        //  'dimensions:min_width=100,min_height=100,max_width=4096,max_height=4096']
    ];
}
```

Only declared constraints emit a rule (`max` is expressed in kilobytes). The **size cap** is the
exception: a bucket that declares no `maxFileSize()` falls back to the package-level
`media.max_file_size`, so an undeclared or unknown bucket yields `['file', 'max:262144']` on the
shipped default. Set `media.max_file_size` to `null` to opt out of a package-level limit.

## Replace in place

Swap a media's underlying original while keeping the **same `id` and `uuid`** — and the same URL,
so existing links and embeds keep working. The new file must satisfy the owner's bucket (mime
allowlist, size cap, dimensions) exactly like an add. The checksum, size, mime type, extension,
dimensions, and placeholders are recomputed and variants are regenerated.

```php
MediaLibrary::replace($media, $request->file('avatar'));   // same id/uuid/url, new bytes
$media->replace($request->file('avatar'));                 // the model shorthand
```

Dedup is respected on both sides: the old original is only physically removed when no other row
still references it, and the new bytes reuse an existing identical file when one exists. The URL
changes in two cases only: when the new bytes deduplicate onto another stored file, and when the
old original is shared with other rows (a deduplicated upload, an `attach()`, a same-disk
`copy()`) — it is then left untouched for them and the new bytes go to a path of this media's own.
`RoundlyConsulting\MediaLibrary\Events\MediaHasBeenReplaced` fires on completion.

## Attach existing media by reference

Link an existing (often global) media to a model **without re-uploading** — a new row is created
that shares the same stored original on the same `(disk, visibility)`, copying **zero bytes**. The
target bucket's variants are generated fresh for the new row.

```php
$logo = MediaLibrary::bucket('brand')->first();

MediaLibrary::for($user)->attach($logo, 'avatar');         // new row, same file, no copy
MediaLibrary::attach($logo, bucket: 'shared');             // …or into another global bucket
$user->attachMedia($logo, 'avatar');                       // the trait shorthand
```

The target bucket's acceptance and single-file rules apply. The shared original stays
refcount-guarded: it survives until the last referrer is deleted or moved away, and replacing the
source never changes the attached row's bytes.

## Artisan commands

```bash
# Regenerate image variants. Optionally filter by model type or ids, limit to named
# variants with --only, and force regeneration of already-generated variants with --force.
php artisan media:regenerate
php artisan media:regenerate "App\Models\User" --ids=1,2,3 --only=thumb,display --force

# Remove orphaned variant files (files in a media's own variants directory it no longer
# records). Conservative: it never touches soft-deleted media, never removes a file any row
# stores as its original, and skips a variants directory the layout shares between media.
php artisan media:clean

# Clear a bucket — delete every media (row + files) in it. Omit the model for global media.
# A model class is mapped through your morph map, so the class name and its alias both work.
php artisan media:clear "App\Models\User" avatar
php artisan media:clear "" brand

# Verify stored media against their checksum baselines. Reports missing or drifted files and
# exits non-zero on any failure — useful after disk migrations or to detect bit-rot.
php artisan media:verify
php artisan media:verify "App\Models\User" --ids=1,2,3

# Prune expired, never-bound draft media (rows + files). Schedule this if you use drafts.
php artisan media:prune-drafts
```

## Events

Every lifecycle step dispatches an event under
`RoundlyConsulting\MediaLibrary\Events` so the host app can react without forking:

| Event | Dispatched when |
|---|---|
| `MediaHasBeenAdded` | A new media row is created (including the new row from a copy). |
| `VariantHasBeenGenerated` | A single image variant finishes generating. |
| `VariantsHaveBeenGenerated` | All of an add's variants finish generating. |
| `MediaHasBeenMoved` | Media is moved across disks, models, or buckets. |
| `MediaHasBeenReplaced` | A media's original is replaced in place. |
| `DraftMediaHasBeenBound` | A draft media is bound to its owning model. |
| `MediaHasBeenDeleted` | A media is permanently deleted — `MediaLibrary::delete()` / `deleteWithFiles()`, once per media on a bucket clear, a draft prune, `media:clear`, or when a `singleFile()` bucket's previous media is replaced. |

## Testing

```bash
composer test
```

Other quality scripts: `composer format` (Pint), `composer analyse` (Larastan level 7), and
`composer test-coverage` (Pest with a 90% line-coverage floor).

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for what has changed recently.

<!-- roundly-support:start -->
## Support our work

This package is free and open source, built and maintained by
[Roundly Consulting](https://roundly-consulting.com/open-source?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=media-library-for-laravel).
If it saves you time, please consider supporting our open-source work — a one-time donation, a
monthly pledge on Patreon or a crypto donation helps fund maintenance, new features and new
packages.

<a href="https://donate.stripe.com/dRmeVe8FX5PF1Qd9pXcEw00"><img src="https://img.shields.io/badge/Donate-Support%20Roundly%20open%20source-F24E29?style=for-the-badge&logo=stripe&logoColor=white" alt="Donate to Roundly open source"></a>
<a href="https://www.patreon.com/cw/roundly"><img src="https://img.shields.io/badge/Patreon-Become%20a%20patron-F96854?style=for-the-badge&logo=patreon&logoColor=white" alt="Become a patron on Patreon"></a>
<a href="https://roundly-consulting.com/support-us?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=media-library-for-laravel#crypto"><img src="https://img.shields.io/badge/Crypto-BTC%20%C2%B7%20ETH%20%C2%B7%20BNB%20%C2%B7%20SOL-F7931A?style=for-the-badge&logo=bitcoin&logoColor=white" alt="Donate crypto: BTC, ETH, BNB or SOL"></a>
<!-- roundly-support:end -->

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
