<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Commands\Concerns;

use Illuminate\Database\Eloquent\Model;
use ReflectionClass;

/**
 * Turns a command's `{model}` argument into the value stored in `media.model_type`: a model class
 * name is mapped through its `getMorphClass()` (so `App\Models\User` finds media stored under a
 * morph-map alias like `user`); anything else — an alias — is used as given.
 *
 * @internal
 */
trait ResolvesModelArgument
{
    private function morphTypeFor(string $model): string
    {
        $class = ltrim($model, '\\');

        if (class_exists($class) && is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract()) {
            return (new $class)->getMorphClass();
        }

        return $model;
    }
}
