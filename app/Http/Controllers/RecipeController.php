<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Recipes\Display\IngredientFormatter;
use App\Recipes\Display\MethodFormatter;

final class RecipeController extends Controller
{
    public function show(
        Recipe $recipe,
        IngredientFormatter $ingredientFormatter,
        MethodFormatter $methodFormatter,
    ) {
        $recipe->load([
            'ingredients',
            'methodSteps',
            'tags',
            'references.resolvedRecipe',
            'referencedBy.recipe',
        ]);

        return view('recipes.show', [
            'recipe' => $recipe,
            'groupedIngredients' => $recipe->groupedIngredients(),
            'ingredientFormatter' => $ingredientFormatter,
            'methodFormatter' => $methodFormatter,
        ]);
    }
}
