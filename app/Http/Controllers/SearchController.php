<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Recipes\Nav\Categories;
use App\Recipes\Search\RecipeSearch;
use Illuminate\Http\Request;

final class SearchController extends Controller
{
    /**
     * Common search suggestions for the empty-results state. Hand-curated for v1.
     * These match content in the current corpus reliably.
     */
    private const SUGGESTED_TERMS = [
        'bread', 'soup', 'dessert', 'garlic', 'buttermilk', 'no-knead', 'honey', 'pie',
    ];

    public function index(Request $request, RecipeSearch $search)
    {
        $q = trim((string) $request->query('q', ''));
        $categoryFilters = array_values(array_filter((array) $request->query('category', [])));
        $tagFilters = array_values(array_filter((array) $request->query('tag', [])));

        $hasFilters = $categoryFilters !== [] || $tagFilters !== [];

        // Landing page: empty query AND no filters.
        if ($q === '' && ! $hasFilters) {
            return view('search.landing', [
                'suggestedTerms' => self::SUGGESTED_TERMS,
            ]);
        }

        $filters = [];
        if ($categoryFilters !== []) {
            $filters['category'] = $categoryFilters;
        }
        if ($tagFilters !== []) {
            $filters['tag'] = $tagFilters;
        }

        $results = $search->query($q, $filters);

        // Build the category filter sidebar — the categories that appear in the
        // result set, plus those currently filtered (so the user can un-filter
        // even if removing the filter would exclude everything currently shown).
        $resultCategories = collect($results->results)
            ->pluck('recipe.category')
            ->merge($categoryFilters)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return view('search.results', [
            'query' => $q,
            'results' => $results,
            'activeCategoryFilters' => $categoryFilters,
            'activeTagFilters' => $tagFilters,
            'availableCategories' => $resultCategories,
            'suggestedTerms' => self::SUGGESTED_TERMS,
        ]);
    }
}
