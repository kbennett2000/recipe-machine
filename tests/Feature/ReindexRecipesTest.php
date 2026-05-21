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

        // All 30 production recipes appear.
        $this->assertSame(30, Recipe::count(), 'Expected 30 recipes after reindex.');

        // All 4 cross-references resolved (apple-pie→pie-crust, french-silk-pie→pie-crust,
        // pretzel-bread-loaves→big-soft-pretzels, royal-sourdough-boule→sourdough-starter).
        $this->assertSame(4, RecipeReference::whereNotNull('resolved_recipe_id')->count());
        $this->assertSame(0, RecipeReference::whereNull('resolved_recipe_id')->count(),
            'No unresolved cross-references should remain.');
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
        $baselinePath = base_path('tests/Fixtures/health-check-baseline.json');
        $baseline = json_decode((string) file_get_contents($baselinePath), true);
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
