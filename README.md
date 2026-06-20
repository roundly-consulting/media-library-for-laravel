<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/media-library-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=media-library-for-laravel">
    <img src="art/hero.png" alt="Media Library for Laravel — Roundly open source" width="100%">
  </a>
</p>
<!-- roundly-hero:end -->

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

Publish and run the migration:

```bash
php artisan vendor:publish --tag="media-migrations"
php artisan migrate
```

Optionally publish the config file:

```bash
php artisan vendor:publish --tag="media-config"
```

The only publish tags the package exposes are `media-migrations` and `media-config`.

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
        'enabled' => true,
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
| `stream.enabled` | `bool` | `true` | — | Register the signed streaming route. |
| `stream.route_prefix` | `string` | `media` | — | URI prefix for the streaming route. |
| `stream.middleware` | `list<string>` | `['web']` | — | Middleware stack for the streaming route. Laravel's `signed` is always appended. |
| `path_generator` | `class-string<PathGenerator>` | `DefaultPathGenerator::class` | — | Directory layout for a media's files. |
| `file_namer` | `class-string<FileNamer>` | `DefaultFileNamer::class` | — | Original and variant file naming. |
| `default_visibility` | `string` | `public` | — | `public` or `private` for new media when a bucket/add doesn't set it. |
| `max_file_size` | `?int` | `268435456` | — | Package-level max upload size in bytes. `null` = no limit. |
| `remote.headers` | `array<string,string>` | `[]` | — | Extra HTTP headers for `addMediaFromUrl()`. |
| `remote.timeout` | `int` | `30` | — | HTTP timeout (seconds) for `addMediaFromUrl()`. |
| `deduplicate` | `bool` | `true` | — | Reuse storage for identical bytes on the same `(disk, visibility)`. |
| `checksum_algorithm` | `string` | `sha256` | — | Hash algorithm for the content checksum (dedup + integrity baseline). |
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

Every `addMedia*` method returns a `PendingFileAdd` builder; a terminal `toMediaBucket()` writes
the file and returns the `Media`:

```php
$user->addMedia($request->file('avatar'))->toMediaBucket('avatar');        // UploadedFile or path
$user->addMediaFromRequest('avatar')->toMediaBucket('avatar');             // request file by key
$user->addMultipleMediaFromRequest(['a', 'b']);                            // returns PendingFileAdd[]
$user->addMediaFromUrl('https://example.com/poster.png')->toMediaBucket('gallery');
$user->addMediaFromDisk('incoming/doc.pdf', 's3')->toMediaBucket('gallery');
$user->addMediaFromString($bytes)->usingFileName('note.txt')->toMediaBucket('gallery');
$user->addMediaFromBase64($base64)->toMediaBucket('gallery');
$user->addMediaFromStream($resource)->usingFileName('upload.bin')->toMediaBucket('gallery');
```

### The `PendingFileAdd` builder

```php
$media = $user->addMedia($request->file('avatar'))
    ->usingName('Profile photo')                 // display name
    ->usingFileName('avatar.jpg')                // stored filename
    ->withCustomProperties(['alt' => 'Jane'])    // arbitrary metadata
    ->withProperty('source', 'signup')           // one property at a time
    ->storingVariantsOnDisk('hot')               // override the bucket/config variants disk
    ->withVisibility('private')                  // override the bucket default
    ->preservingOriginal()                       // copy the source instead of moving it
    ->onQueue('media')                           // queue any queued variants on this queue
    ->toMediaBucket('avatar', 'cold');           // terminal: returns Media (optional disk override)
```

The terminal call validates the file (mime allowlist, size, existence) and throws a typed
exception — `FileUnacceptableForBucket`, `FileDoesNotExist`, or `DiskDoesNotExist` — on failure.

### Reading media

```php
$user->getMedia('avatar');                       // Collection<Media>, ordered
$user->getFirstMedia('avatar');                  // ?Media
$user->getFirstMediaUrl('avatar');               // public URL, the bucket fallback, or ''
$user->getFirstMediaUrl('avatar', 'thumb');      // a variant's URL
$user->hasMedia('avatar');                       // bool
$user->clearMediaBucket('avatar');               // delete every media in the bucket
```

Store global media (no owning model) via the `Media` facade:

```php
use RoundlyConsulting\MediaLibrary\Facades\Media;

$logo = Media::add(storage_path('brand/logo.svg'))
    ->usingName('Primary logo')
    ->toBucket('brand');

Media::bucket('brand')->get();
Media::find($logo->uuid);
```

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
$media->hasGeneratedVariant('thumb');
```

### Drivers

Variants use `ext-imagick` by default and automatically fall back to `ext-gd`. Choose the
driver explicitly with `config('media.image_driver')` (`imagick` | `gd`). When neither
extension is installed and a variant is requested, a `VariantDriverUnavailable` exception is
thrown.

### Sync vs. queued

Variants are generated **synchronously** by default. Opt into the queue per variant
(`->queued()`), per add (`$user->addMedia($file)->onQueue('media')->toMediaBucket('photos')`),
or globally (`config('media.queue_variants_by_default')`). Queued variants are processed by the
`GenerateVariantsJob`, which carries only the media id and variant names.

### Variants disk resolution

The disk a variant is stored on is resolved by precedence: the variant's `storeOnDisk()`, then
the add's `storingVariantsOnDisk()`, then the bucket's `storingVariantsOnDisk()`, then
`config('media.variants_disk')`, falling back to the media's own disk.

### Events

`VariantHasBeenGenerated` fires per variant and `VariantsHaveBeenGenerated` once all of an
add's variants are done — listen for either to react to generated derivatives.

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
$media->width;                 // e.g. 1920 (null for non-images)
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
attachment when `?download=1` is in the signed URL. Private media is reachable only through a
valid signature (or a native presigned URL).

### Streaming from your own controller

The model can build the responses directly, so you can stream from a controller you already
own without the package route:

```php
public function show(Request $request, Media $media): StreamedResponse
{
    return $media->toResponse($request);            // inline, range-aware
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
$media->moveToDisk('cold');

// Move only the variant files to another disk; the original stays put.
$media->moveVariantsToDisk('hot');

// Re-home to a different model and bucket (deletes the source files).
$media->move($otherUser, 'gallery');

// Move a model's media to global storage (no owner).
$media->move(null, 'brand');

// Copy instead of move: a new row with a fresh UUID, source preserved.
$copy = $media->copy($otherUser, 'gallery', 'cold');
```

Both fire events — `MediaHasBeenMoved` after a move, and `MediaHasBeenAdded` for the new row a
copy creates. `MoveMediaAction`, `CopyMediaAction` and `DeleteMediaAction` are resolvable from the
container if you prefer calling them directly.

To permanently delete a media with its files, use `deleteWithFiles()` (or the
`DeleteMediaAction`). A plain `delete()` is a soft delete and **keeps** the files so a restore
stays lossless; only a force delete (or `deleteWithFiles()`) removes them, which fires
`MediaHasBeenDeleted`.

## Deduplication & integrity

On every add the package records a content **checksum** (`sha256` by default, via
`media.checksum_algorithm`) and stores the original's path on the row. With deduplication on
(`media.deduplicate`, default `true`), two adds whose bytes are identical on the **same disk and
visibility** share a single physical original — the bytes are written once and both rows resolve
to the same file. A different disk or visibility is a genuinely different storage location, so it
is stored separately by design. Variants are never shared: each media generates its own variants
under its own UUID directory.

Sharing is made safe by **refcount-guarded** delete and move: before a physical original is
removed (or its source dropped on a cross-disk move), the package checks whether any other
non-deleted row still references the same `(disk, visibility, checksum)`. The file is only deleted
when the last referrer goes; a still-shared original is copied to the new disk on move and the
source is left in place.

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
(default 24h, `media.drafts.ttl`). Variants and placeholders are still computed on add.

```php
use RoundlyConsulting\MediaLibrary\Facades\Media;

// Upload step — no model yet. Hand the token back to the client.
$draft = Media::draft($request->file('avatar'))->toBucket('avatar');
$token = $draft->draft_token;

// The model trait builder can also stage a draft:
$user->addMedia($file)->asDraft()->toMediaBucket('avatar');

// When the form is submitted and the model is saved, bind by token:
$user->attachDraftMedia($token, 'avatar');   // sets the owner, clears the token
```

Binding throws `DraftMediaNotFound` (unknown / already-bound token) or `DraftMediaExpired` (past
TTL). Schedule `media:prune-drafts` to delete expired, never-bound drafts.

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
        'avatar' => Media::rulesFor(User::class, 'avatar'),
        // ['file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120',
        //  'dimensions:min_width=100,min_height=100,max_width=4096,max_height=4096']
    ];
}
```

Only declared constraints emit a rule (`max` is expressed in kilobytes); an undeclared or unknown
bucket yields `['file']`.

## Replace in place

Swap a media's underlying original while keeping the **same `id`, `uuid`, and URL**, so existing
links and embeds keep working. The checksum, size, mime type, extension, dimensions, and
placeholders are recomputed and variants are regenerated.

```php
$media->replace($request->file('avatar'));   // same id/uuid/url, new bytes
```

Dedup is respected on both sides: the old original is only physically removed when no other row
still references it, and the new bytes reuse an existing identical file when one exists.
`RoundlyConsulting\MediaLibrary\Events\MediaHasBeenReplaced` fires on completion.

## Attach existing media by reference

Link an existing (often global) media to a model **without re-uploading** — a new row is created
that shares the same stored original on the same `(disk, visibility)`, copying **zero bytes**. The
target bucket's variants are generated fresh for the new row.

```php
$logo = Media::bucket('brand')->first();
$user->attachMedia($logo, 'avatar');          // new row, same file, no copy
```

The shared original stays refcount-guarded: it survives until the last referrer is deleted or moved
away.

## Artisan commands

```bash
# Regenerate image variants. Optionally filter by model type or ids, limit to named
# variants with --only, and force regeneration of already-generated variants with --force.
php artisan media:regenerate
php artisan media:regenerate "App\Models\User" --ids=1,2,3 --only=thumb,display --force

# Remove orphaned variant files (files in a media's variants directory it no longer
# references). Conservative: it never touches soft-deleted media.
php artisan media:clean

# Clear a bucket — delete every media (row + files) in it. Omit the model for global media.
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
| `MediaHasBeenDeleted` | A media is permanently deleted (force delete / `deleteWithFiles()`). |

## Testing

```bash
composer test
```

Other quality scripts: `composer format` (Pint), `composer analyse` (Larastan level 7), and
`composer test-coverage` (Pest with a 90% line-coverage floor).

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for what has changed recently.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
