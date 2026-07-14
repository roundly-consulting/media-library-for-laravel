<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Tests;

use Illuminate\Foundation\Application;
use RoundlyConsulting\MediaLibrary\Tests\Fixtures\CustomMedia;

/**
 * Boots the package with a host's own media model configured — the swap `media.media_model`
 * documents. The model must be set before the provider boots, so the media observer is registered
 * against the configured model and *only* the configured model: an observer left on the packaged
 * model would mask a call site that bypasses the seam.
 */
abstract class ConfiguredModelTestCase extends TestCase
{
    /** @param  Application  $app */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('media.media_model', CustomMedia::class);
    }
}
