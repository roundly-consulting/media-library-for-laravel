<!-- roundly-hero:start -->
<p align="center">
  <a href="https://roundly-consulting.com/open-source/docs/media-library-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=media-library-for-laravel">
    <img src="https://raw.githubusercontent.com/roundly-consulting/media-library-for-laravel/main/art/hero.png" alt="Media Library for Laravel — Roundly open source" width="100%">
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

A native Laravel media library: attach files to your Eloquent models in named buckets, or store
global media with no owner, on any Laravel disk. Image variants, private signed URLs,
deduplicated storage and blur-up placeholders come built in.

## Installation

Requires PHP 8.4, Laravel 12 or 13, `ext-fileinfo`, and `ext-imagick` or `ext-gd` for image
variants.

```bash
composer require roundly-consulting/media-library-for-laravel
php artisan vendor:publish --tag="media-migrations"
php artisan migrate
```

## Usage

Declare the model's buckets once:

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
            ->singleFile()                                   // a new upload replaces the old one
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->registerVariants(function (VariantRegistrar $v): void {
                $v->add('thumb')->fit('crop')->width(120)->height(120)->format('webp');
            });
    }
}
```

Then add and read media through the facade:

```php
use RoundlyConsulting\MediaLibrary\Facades\MediaLibrary;

$avatar = MediaLibrary::for($user);

$media = $avatar->add($request->file('avatar'))->toBucket('avatar');  // validated, stored, thumb rendered

$avatar->url('avatar');              // the original's URL
$avatar->url('avatar', 'thumb');     // the 120×120 WebP variant
$media->placeholderDataUri();        // a tiny blur-up image to show while it loads
$avatar->clear('avatar');            // delete the media and its files
```

<!-- roundly-docs:start -->
## Documentation

The full documentation — configuration, every feature and its API, and testing — lives on our
website: **[roundly-consulting.com/open-source/docs/media-library-for-laravel](https://roundly-consulting.com/open-source/docs/media-library-for-laravel?utm_source=github&utm_medium=readme&utm_campaign=open-source&utm_content=media-library-for-laravel)**

Release notes are in [CHANGELOG.md](CHANGELOG.md). To contribute, see the
[contributing guide](https://github.com/roundly-consulting/.github/blob/main/CONTRIBUTING.md).
<!-- roundly-docs:end -->

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
