<?php

declare(strict_types=1);

use RoundlyConsulting\MediaLibrary\Tests\ConfiguredModelTestCase;
use RoundlyConsulting\MediaLibrary\Tests\TestCase;

uses(TestCase::class)->in(__DIR__.'/Feature', __DIR__.'/Unit');

// The media-model swap must be configured before the app boots, so its tests get their own case.
uses(ConfiguredModelTestCase::class)->in(__DIR__.'/Configured');
