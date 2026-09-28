<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

use Illuminate\Database\Eloquent\Model;

/**
 * Thrown when a model-scoped call (`MediaLibrary::for($model)->delete($media)`) is handed media
 * owned by another model, or global media. The scope is a security boundary: a handle never
 * reaches outside its owner.
 */
final class MediaDoesNotBelongToModel extends MediaLibraryException
{
    public static function make(string $uuid, Model $model): self
    {
        return new self("Media [{$uuid}] does not belong to [".$model->getMorphClass().'#'.$model->getKey().'].');
    }
}
