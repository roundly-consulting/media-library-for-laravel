<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Tests\Fixtures;

use RoundlyConsulting\MediaLibrary\Models\Media;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * A host's own media model, the swap `media.media_model` documents.
 *
 * `CountsCreations` is what makes the swap proof independent: it counts rows created as *this
 * exact class*, so a media row created as the packaged Media — which would still pass an
 * `instanceof` check while firing none of the host's model events — cannot be mistaken for an
 * honoured swap. In media that distinction is not academic: the observer that deletes files off
 * disk hangs on the configured model's events, which is exactly how #28 orphaned files.
 */
final class CustomMedia extends Media
{
    use CountsCreations;
}
