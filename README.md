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

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
