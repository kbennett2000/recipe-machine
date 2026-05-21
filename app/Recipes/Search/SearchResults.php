<?php

declare(strict_types=1);

namespace App\Recipes\Search;

/**
 * The complete result of a single RecipeSearch::query() call.
 *
 * `$truncated` is true when the underlying query returned more rows than the
 * hard cap (50 in v1). UI surfaces this as "Showing top N results."
 */
final class SearchResults
{
    /**
     * @param  array<SearchResult>  $results
     * @param  string|null  $query         The user's raw query (null when browse-mode).
     * @param  array<string,mixed>  $filters  The filters that were applied.
     * @param  int   $totalCount         How many results were actually returned (≤ $cap).
     * @param  bool  $truncated          True if more matches existed than the cap.
     * @param  int   $cap                The hard cap that was applied (default 50).
     */
    public function __construct(
        public readonly array $results,
        public readonly ?string $query,
        public readonly array $filters,
        public readonly int $totalCount,
        public readonly bool $truncated,
        public readonly int $cap = 50,
    ) {}

    public function isEmpty(): bool
    {
        return $this->totalCount === 0;
    }

    /** @return array<string> */
    public function slugs(): array
    {
        return array_map(fn (SearchResult $r) => $r->recipe->slug, $this->results);
    }

    public function count(): int
    {
        return $this->totalCount;
    }
}
