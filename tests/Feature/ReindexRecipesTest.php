<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeReference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ReindexRecipesTest extends TestCase
{
    use RefreshDatabase;

    public function test_reindex_against_full_corpus(): void
    {
        $this->artisan('recipes:reindex')->assertSuccessful();

        // Reindex must pick up every recipe enumerated in the health-check
        // baseline. The baseline is the source of truth for "what's in the
        // corpus" — when a recipe is added, you run
        // `php artisan recipes:health-check --update-baseline` to refresh
        // it, and this assertion follows the new count automatically.
        $baseline = $this->loadBaseline();
        $expectedRecipes = count($baseline['recipes']);
        $this->assertSame($expectedRecipes, Recipe::count(),
            "Expected {$expectedRecipes} recipes after reindex (from baseline).");

        // Every recipe enumerated in the baseline should have a DB row.
        foreach (array_keys($baseline['recipes']) as $slug) {
            $this->assertTrue(
                Recipe::query()->where('slug', $slug)->exists(),
                "Baseline recipe '{$slug}' is missing from the DB after reindex."
            );
        }

        // Structural check: every reference points at an actual recipe.
        // The count itself isn't load-bearing — what matters is that nothing
        // ends up unresolved.
        $this->assertSame(0, RecipeReference::whereNull('resolved_recipe_id')->count(),
            'No unresolved cross-references should remain.');
    }

    /** @return array{recipes: array<string, array<string, int>>} */
    private function loadBaseline(): array
    {
        $path = base_path('tests/Fixtures/health-check-baseline.json');
        $data = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($data, "Baseline at {$path} did not decode as JSON.");
        return $data;
    }

    public function test_honey_oat_bread_scalar_fields(): void
    {
        $this->artisan('recipes:reindex')->assertSuccessful();

        $honey = Recipe::where('slug', 'honey-oat-bread')->first();
        $this->assertNotNull($honey);
        $this->assertSame('Honey Oat Bread', $honey->title);
        $this->assertSame('breads', $honey->category);
        $this->assertSame('350F', $honey->oven_temp);
        $this->assertSame('40m', $honey->cook_time);
        $this->assertSame(7, $honey->ingredients()->count());
    }

    public function test_corpus_ingredient_count_matches_health_check_baseline(): void
    {
        $this->artisan('recipes:reindex')->assertSuccessful();
        $baseline = $this->loadBaseline();
        $expectedTotal = 0;
        foreach ($baseline['recipes'] as $r) {
            $expectedTotal += $r['ingredient_count'];
        }
        $this->assertSame($expectedTotal, \App\Models\Ingredient::count(),
            'Ingredient count after reindex should match the health-check baseline total.');
    }

    public function test_reindex_is_idempotent(): void
    {
        // Two consecutive reindexes should produce identical row counts.
        $this->artisan('recipes:reindex')->assertSuccessful();
        $first = [
            'recipes' => Recipe::count(),
            'ingredients' => \App\Models\Ingredient::count(),
            'method_steps' => \App\Models\MethodStep::count(),
            'references' => RecipeReference::count(),
        ];
        $this->artisan('recipes:reindex')->assertSuccessful();
        $second = [
            'recipes' => Recipe::count(),
            'ingredients' => \App\Models\Ingredient::count(),
            'method_steps' => \App\Models\MethodStep::count(),
            'references' => RecipeReference::count(),
        ];
        $this->assertSame($first, $second, 'recipes:reindex must be idempotent.');
    }
}
