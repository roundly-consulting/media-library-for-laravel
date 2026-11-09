<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Tests\Fixtures;

use RoundlyConsulting\MediaLibrary\Contracts\PathGenerator;
use RoundlyConsulting\MediaLibrary\Models\Media;

/** A host layout that puts the owner (the "tenant") first: `tenants/{model_id}/{uuid}/`. */
final class TenantPathGenerator implements PathGenerator
{
    public function getPath(Media $media): string
    {
        return 'tenants/'.($media->model_id ?? 'none').'/'.$media->uuid.'/';
    }

    public function getPathForVariants(Media $media): string
    {
        return $this->getPath($media).'variants/';
    }
}
