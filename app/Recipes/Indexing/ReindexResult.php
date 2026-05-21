<?php

declare(strict_types=1);

namespace App\Recipes\Indexing;

/**
 * Phase 11B — result of a RecipeReindexer operation.
 *
 * Both reindexOne() and remove() return this shape so callers can introspect
 * what happened (counts + status + elapsed time) without re-querying the DB.
 *
 * Status enum:
 *   - created:   reindexOne wrote a recipe that didn't exist in the DB before.
 *   - updated:   reindexOne updated an existing recipe.
 *   - deleted:   remove() dropped an existing recipe.
 *   - unchanged: reindexOne ran but the parsed result was identical to the
 *                existing DB state. (Reserved; current impl always writes,
 *                so this status isn't emitted yet — kept in the contract.)
 *   - not_found: reindexOne couldn't locate the .md file on disk, or
 *                remove() was called on a slug that wasn't in the DB.
 */
final class ReindexResult
{
    public function __construct(
        public readonly string $slug,
        public readonly string $status,
        public readonly array $changes,
        public readonly int $elapsedMs,
    ) {}

    public static function notFound(string $slug, int $elapsedMs): self
    {
        return new self(slug: $slug, status: 'not_found', changes: [], elapsedMs: $elapsedMs);
    }
}
