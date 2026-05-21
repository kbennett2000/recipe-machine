<?php

declare(strict_types=1);

namespace App\Recipes\Search;

use App\Models\Recipe;

/**
 * One search hit: a recipe plus the bm25 rank, matched-fields list, and
 * highlighted snippets per matched field.
 */
final class SearchResult
{
    /**
     * @param  Recipe  $recipe          The matched recipe (eager-loadable).
     * @param  float   $rank            FTS5 bm25 score. Lower (more negative) is a better match. Always 0.0 for browse-mode results.
     * @param  array<string>  $matchedIn  Which fields contained the match: any of {title, ingredients, method, notes, libation}.
     * @param  array<string,string>  $snippets  Field name → HTML-safe snippet with `<mark>` highlights, one per matched field.
     */
    public function __construct(
        public readonly Recipe $recipe,
        public readonly float $rank,
        public readonly array $matchedIn,
        public readonly array $snippets,
    ) {}
}
