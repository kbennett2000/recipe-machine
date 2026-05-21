<?php

declare(strict_types=1);

namespace App\Recipes\Search;

use App\Models\Recipe;
use Illuminate\Support\Facades\DB;

/**
 * Full-text search service.
 *
 * Two paths:
 *   1. Empty query → "browse" mode. Returns recipes matching the filters
 *      alphabetically. No FTS5 involvement.
 *   2. Non-empty query → FTS5 MATCH against `recipe_search`, ordered by bm25
 *      (with the title column weighted 5x to keep title hits at the top),
 *      then filters applied via JOIN on `recipes` and/or `recipe_tags`.
 *
 * Result cap: 50 in v1. No pagination UI yet — the cap surfaces as a
 * "Showing top 50 results" note via SearchResults::$truncated.
 */
final class RecipeSearch
{
    /** Hard upper bound on results returned in a single query. */
    private const CAP = 50;

    /**
     * Column weights for bm25. Order matches the FTS table definition:
     * slug (UNINDEXED, ignored), title, ingredients_text, method_text,
     * notes_text, libation_text. Title is boosted so "honey oat" against a
     * recipe titled "Honey Oat Bread" ranks above one that merely mentions
     * honey and oats in the body.
     */
    private const BM25_WEIGHTS = [0, 5.0, 1.0, 1.0, 0.5, 0.5];

    /** FTS5 snippet markers. Replaced with `<mark>` after the snippet text is HTML-escaped. */
    private const HL_OPEN = "\x01HL\x01";
    private const HL_CLOSE = "\x01/HL\x01";

    public function __construct(
        private readonly MatchQueryBuilder $queryBuilder = new MatchQueryBuilder,
    ) {}

    /**
     * @param  array{category?: string|array<string>, tag?: string|array<string>, has_unparsed?: bool}  $filters
     */
    public function query(string $q, array $filters = []): SearchResults
    {
        $q = trim($q);
        if ($q === '') {
            return $this->browse($filters);
        }
        $matchExpr = $this->queryBuilder->build($q);
        if ($matchExpr === null) {
            // Input was non-empty but had no usable tokens after sanitization.
            return new SearchResults(
                results: [],
                query: $q,
                filters: $filters,
                totalCount: 0,
                truncated: false,
            );
        }
        return $this->fts($q, $matchExpr, $filters);
    }

    /**
     * Empty-query "browse" path — filter-only, ordered alphabetically by title.
     *
     * @param  array<string,mixed>  $filters
     */
    private function browse(array $filters): SearchResults
    {
        $builder = Recipe::query();
        $this->applyFiltersToEloquent($builder, $filters);
        $recipes = $builder->orderBy('title')->take(self::CAP + 1)->get();

        $truncated = $recipes->count() > self::CAP;
        if ($truncated) {
            $recipes = $recipes->take(self::CAP);
        }
        $results = $recipes->map(fn (Recipe $r) => new SearchResult(
            recipe: $r,
            rank: 0.0,
            matchedIn: [],
            snippets: [],
        ))->all();

        return new SearchResults(
            results: $results,
            query: null,
            filters: $filters,
            totalCount: count($results),
            truncated: $truncated,
        );
    }

    /**
     * FTS5 query path.
     *
     * @param  array<string,mixed>  $filters
     */
    private function fts(string $userQuery, string $matchExpr, array $filters): SearchResults
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            // FTS5 is SQLite-only. Fall back to a LIKE query for non-SQLite drivers.
            return $this->fallbackLike($userQuery, $filters);
        }

        $bm25 = sprintf(
            'bm25(recipe_search, %s)',
            implode(', ', array_map(fn ($w) => (string) $w, self::BM25_WEIGHTS)),
        );

        $sql = "
            SELECT
                recipes.id,
                recipes.slug,
                {$bm25} AS rank,
                snippet(recipe_search, 1, ?, ?, '…', 10) AS snippet_title,
                snippet(recipe_search, 2, ?, ?, '…', 12) AS snippet_ingredients,
                snippet(recipe_search, 3, ?, ?, '…', 12) AS snippet_method,
                snippet(recipe_search, 4, ?, ?, '…', 12) AS snippet_notes,
                snippet(recipe_search, 5, ?, ?, '…', 12) AS snippet_libation
            FROM recipe_search
            JOIN recipes ON recipes.slug = recipe_search.slug
        ";
        $bindings = array_fill(0, 10, '');
        $hl = [self::HL_OPEN, self::HL_CLOSE];
        $bindings = [
            $hl[0], $hl[1], $hl[0], $hl[1], $hl[0], $hl[1], $hl[0], $hl[1], $hl[0], $hl[1],
        ];

        $whereParts = ['recipe_search MATCH ?'];
        $bindings[] = $matchExpr;

        $this->appendFilterClauses($sql, $whereParts, $bindings, $filters);

        $sql .= ' WHERE '.implode(' AND ', $whereParts);
        $sql .= ' ORDER BY rank LIMIT '.(self::CAP + 1);

        $rows = DB::select($sql, $bindings);

        $truncated = count($rows) > self::CAP;
        if ($truncated) {
            $rows = array_slice($rows, 0, self::CAP);
        }

        $results = [];
        $ids = array_map(fn ($r) => $r->id, $rows);
        $recipes = Recipe::whereIn('id', $ids)->get()->keyBy('id');

        foreach ($rows as $row) {
            $recipe = $recipes->get($row->id);
            if ($recipe === null) {
                continue;
            }
            [$matchedIn, $snippets] = $this->extractSnippets((array) $row);
            $results[] = new SearchResult(
                recipe: $recipe,
                rank: (float) $row->rank,
                matchedIn: $matchedIn,
                snippets: $snippets,
            );
        }

        return new SearchResults(
            results: $results,
            query: $userQuery,
            filters: $filters,
            totalCount: count($results),
            truncated: $truncated,
        );
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    private function fallbackLike(string $userQuery, array $filters): SearchResults
    {
        // Non-SQLite path: best-effort LIKE search on recipe title.
        // Not great, but keeps the service usable in environments without FTS5.
        $builder = Recipe::query()->where('title', 'like', '%'.$userQuery.'%');
        $this->applyFiltersToEloquent($builder, $filters);
        $recipes = $builder->orderBy('title')->take(self::CAP)->get();

        $results = $recipes->map(fn ($r) => new SearchResult(
            recipe: $r,
            rank: 0.0,
            matchedIn: ['title'],
            snippets: ['title' => e($r->title)],
        ))->all();

        return new SearchResults(
            results: $results,
            query: $userQuery,
            filters: $filters,
            totalCount: count($results),
            truncated: false,
        );
    }

    /**
     * @param  array<string,mixed>  $rowArray  Associative form of the DB row.
     * @return array{0: array<string>, 1: array<string,string>}
     */
    private function extractSnippets(array $rowArray): array
    {
        $columns = [
            'snippet_title'       => 'title',
            'snippet_ingredients' => 'ingredients',
            'snippet_method'      => 'method',
            'snippet_notes'       => 'notes',
            'snippet_libation'    => 'libation',
        ];
        $matchedIn = [];
        $snippets = [];
        foreach ($columns as $col => $field) {
            $raw = (string) ($rowArray[$col] ?? '');
            if ($raw === '') {
                continue;
            }
            // The snippet contains our sentinel markers iff the column actually
            // matched. If it doesn't, FTS5 returns leading text from the column
            // (unhighlighted) — we skip those.
            if (! str_contains($raw, self::HL_OPEN)) {
                continue;
            }
            $matchedIn[] = $field;
            $escaped = e($raw);
            $snippets[$field] = str_replace(
                [e(self::HL_OPEN), e(self::HL_CLOSE)],
                ['<mark>', '</mark>'],
                $escaped,
            );
        }
        return [$matchedIn, $snippets];
    }

    /**
     * Apply filters to a string SQL query — appends to $whereParts / $bindings in place.
     *
     * @param  array<string,mixed>  $filters
     * @param  array<string>  $whereParts
     * @param  array<mixed>  $bindings
     */
    private function appendFilterClauses(string &$sql, array &$whereParts, array &$bindings, array $filters): void
    {
        if (! empty($filters['category'])) {
            $cats = (array) $filters['category'];
            $placeholders = implode(', ', array_fill(0, count($cats), '?'));
            $whereParts[] = "recipes.category IN ({$placeholders})";
            foreach ($cats as $c) {
                $bindings[] = (string) $c;
            }
        }
        if (! empty($filters['tag'])) {
            $tags = (array) $filters['tag'];
            $placeholders = implode(', ', array_fill(0, count($tags), '?'));
            $whereParts[] = "recipes.id IN (SELECT recipe_id FROM recipe_tags WHERE tag IN ({$placeholders}))";
            foreach ($tags as $t) {
                $bindings[] = (string) $t;
            }
        }
        if (! empty($filters['has_unparsed'])) {
            $whereParts[] = 'recipes.id IN (SELECT recipe_id FROM ingredients WHERE parsed = 0)';
        }
    }

    /**
     * Apply filters to an Eloquent builder (browse-mode path).
     *
     * @param  array<string,mixed>  $filters
     */
    private function applyFiltersToEloquent(\Illuminate\Database\Eloquent\Builder $builder, array $filters): void
    {
        if (! empty($filters['category'])) {
            $builder->whereIn('category', (array) $filters['category']);
        }
        if (! empty($filters['tag'])) {
            $builder->whereHas('tags', fn ($q) => $q->whereIn('tag', (array) $filters['tag']));
        }
        if (! empty($filters['has_unparsed'])) {
            $builder->whereHas('ingredients', fn ($q) => $q->where('parsed', false));
        }
    }
}
