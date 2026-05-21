<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Recipe;

/**
 * Phase 8: /recipes — the maintainer's index. Lists every recipe with its
 * outgoing and incoming references as small chips, plus a free-text Alpine
 * filter. Useful for spotting "isolated" recipes that could use a [[ref]]
 * or a see-also bridge.
 */
final class RecipeIndexController extends Controller
{
    public function show()
    {
        $recipes = Recipe::query()
            ->with([
                'references' => function ($q) {
                    $q->whereNotNull('resolved_recipe_id');
                },
                'references.resolvedRecipe:id,slug,title',
                'referencedBy.recipe:id,slug,title',
            ])
            ->orderBy('title')
            ->get();

        return view('recipes.index', [
            'recipes' => $recipes,
        ]);
    }
}
