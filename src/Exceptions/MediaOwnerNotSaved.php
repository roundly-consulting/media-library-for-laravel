<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Exceptions;

use Illuminate\Database\Eloquent\Model;

/**
 * Media can only belong to a model that is saved and has a key: a keyless owner would store its
 * media with `model_id` NULL, where every other keyless owner's media lives too.
 */
final class MediaOwnerNotSaved extends MediaLibraryException
{
    public static function forModel(Model $model): self
    {
        return new self('Media cannot belong to a ['.$model->getMorphClass().'] that has not been saved; save it first.');
    }
}
