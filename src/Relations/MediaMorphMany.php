<?php

declare(strict_types=1);

namespace RoundlyConsulting\MediaLibrary\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * The `media()` relation, with Eloquent's integer-key eager-load optimisation switched off.
 *
 * `media.model_id` is a deliberate string column so owners keyed by int, uuid or ulid are all
 * stored without coercion. Eloquent's `Relation::whereInMethod()` does not know that: it infers
 * the FOREIGN column's type from the PARENT's key type —
 *
 *     $model->getKeyName() === last(explode('.', $key))
 *         && in_array($model->getKeyType(), ['int', 'integer'])
 *             ? 'whereIntegerInRaw'
 *             : 'whereIn'
 *
 * — which is sound for an ordinary `hasMany` (where the two columns really do match) and wrong
 * for a string morph. For an int-keyed owner it picked `whereIntegerInRaw`, which INLINES the
 * keys as SQL literals instead of binding them: `where "media"."model_id" in (1, 2)`. Postgres
 * then compares `character varying = integer`, has no implicit cast between them, and refuses the
 * query — so `->load('media')` and `with('media')` threw for EVERY int-keyed host, while lazy
 * loading worked because it binds the key as a parameter (inferred as unknown, resolved to
 * varchar). SQLite's dynamic typing hid the whole thing, and the suite only ever lazy-loaded.
 *
 * Forcing `whereIn` restores the invariant the column actually has and makes the eager path bind
 * exactly like the lazy one. This is scoped to the media relation rather than overriding the
 * host's `newMorphMany()`, which would change every unrelated morphMany the host declares.
 *
 * Stringifying the keys as well was measured and deliberately NOT kept: with `whereIn` the bound
 * parameter already resolves to varchar on every driver, so it changed no outcome — and the lazy
 * path has always relied on that same inference.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphMany<TRelatedModel, TDeclaringModel>
 */
final class MediaMorphMany extends MorphMany
{
    /**
     * Always bind, never inline. `whereIntegerInRaw` casts the keys to ints and writes them
     * straight into the SQL, which a string `model_id` cannot be compared against on a strict
     * engine.
     *
     * @param  string  $key
     * @return string
     */
    protected function whereInMethod(Model $model, $key)
    {
        return 'whereIn';
    }
}
