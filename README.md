# Media Library for Laravel

A native Laravel media library: attach files to your Eloquent models in named **buckets**,
or store **global** media with no owning model — all persisted in a single polymorphic
`media` table and stored through Laravel's own filesystem, on any disk you configure.

Built with only official Laravel and Symfony dependencies. No third-party media or image
vendors.

> This package is under active development. The foundation below (storage core: model media
> buckets, global media, and multi-disk support) is available now. Image variants, signed
> private URLs and streaming, move/copy, content-addressable dedup, LQIP placeholders,
> responsive `srcset`, and draft media are planned in upcoming phases.

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

## Quick start

Add the contract and trait to any model, and declare its buckets:

```php
use Illuminate\Database\Eloquent\Model;
use RoundlyConsulting\MediaLibrary\Concerns\InteractsWithMedia;
use RoundlyConsulting\MediaLibrary\Contracts\HasMedia;

final class User extends Model implements HasMedia
{
    use InteractsWithMedia;

    public function registerMediaBuckets(): void
    {
        $this->addMediaBucket('avatar')
            ->singleFile()
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->useDisk('public');
    }
}
```

Attach and read media:

```php
$user->addMedia($request->file('avatar'))->toMediaBucket('avatar');

$user->getFirstMediaUrl('avatar');   // public URL, or '' when empty
$user->getMedia('avatar');           // Collection<Media>
$user->hasMedia('avatar');           // bool
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
```

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
