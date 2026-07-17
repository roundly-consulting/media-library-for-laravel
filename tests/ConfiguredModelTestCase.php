<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Tests;

use RoundlyConsulting\MediaLibrary\Tests\Fixtures\CustomMedia;

/**
 * Boots the package with a host's own media model configured — the swap `media.media_model`
 * documents. The model must be set before the provider boots, so the media observer is registered
 * against the configured model and *only* the configured model: an observer left on the packaged
 * model would mask a call site that bypasses the seam.
 *
 * This was a `defineEnvironment()` override. It called `parent::` and so was correct, but the
 * base case now does its whole job in `defineEnvironment()` (DriverMatrix::configure + the
 * before-boot config + model swaps), which makes that override one missing `parent::` call away
 * from silently decapitating the base — no error, no red, DriverMatrix simply never configured.
 * `configBeforeBoot()` is the seam meant for this, and its `array_merge(parent::…)` keeps the
 * base's disks and app.key.
 *
 * @see TestCase
 */
abstract class ConfiguredModelTestCase extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function configBeforeBoot(): array
    {
        return array_merge(parent::configBeforeBoot(), [
            'media.media_model' => CustomMedia::class,
        ]);
    }
}
