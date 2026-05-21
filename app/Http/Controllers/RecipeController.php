<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Recipe;
use App\Recipes\Cooking\CookingStepFormatter;
use App\Recipes\Display\IngredientFormatter;
use App\Recipes\Display\MethodFormatter;
use Illuminate\Http\Request;

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

    /**
     * Cooking mode: full-screen, big-text, step-by-step. URL takes ?step=N;
     * out-of-range values clamp to the valid range (1..N).
     *
     * For zero-method recipes (pasta-sauce, sourdough-starter): render a
     * graceful placeholder rather than 404 — the recipe page still has
     * useful content the user might want to glance back at, and the cook
     * route is the discoverable entry from the recipe page itself.
     */
    public function cook(
        Request $request,
        Recipe $recipe,
        IngredientFormatter $ingredientFormatter,
        CookingStepFormatter $stepFormatter,
    ) {
        $recipe->load(['ingredients', 'methodSteps']);

        $totalSteps = $recipe->methodSteps->count();
        $requestedStep = (int) $request->query('step', 1);
        $startStep = max(1, min($totalSteps > 0 ? $totalSteps : 1, $requestedStep));

        // Pre-render every step's HTML server-side. The view shows one at a
        // time via x-show, swapping based on Alpine state.
        $renderedSteps = $recipe->methodSteps->map(fn ($step) => [
            'position' => $step->position,
            'html' => $stepFormatter->format($step->content),
        ]);

        return view('recipes.cook', [
            'recipe' => $recipe,
            'totalSteps' => $totalSteps,
            'startStep' => $startStep,
            'renderedSteps' => $renderedSteps,
            'groupedIngredients' => $recipe->groupedIngredients(),
            'ingredientFormatter' => $ingredientFormatter,
        ]);
    }
}
