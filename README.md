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

## Testing

```bash
composer test
```

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
