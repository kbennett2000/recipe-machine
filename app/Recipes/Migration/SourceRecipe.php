<?php

declare(strict_types=1);

namespace App\Recipes\Migration;

/**
 * One recipe section pulled out of a source codex file.
 *
 * Holds the raw title and body; downstream stages do the actual
 * extraction work (frontmatter mining, body normalization, slug, etc).
 */
final class SourceRecipe
{
    public function __construct(
        /** Raw title from the `## ` header, with any leading `NN. ` prefix already stripped. */
        public readonly string $title,
        /** Recipe body — everything between this section's `## ` header and the next one. */
        public readonly string $body,
        /** Line number in the source file where the `## ` header lives (1-indexed; for error messages). */
        public readonly int $sourceLine = 0,
        /** Optional per-recipe category, set by SourceParser when in hierarchical mode (`## Category` then `### Recipe`). Null in flat mode — the Migrator falls back to the --category command argument. */
        public readonly ?string $category = null,
    ) {}
}
