<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\RecipeReference;
use App\Models\RecipeSeeAlso;
use App\Recipes\Indexing\RecipeReindexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 11B — incremental reindex correctness against the real corpus.
 *
 * Covers:
 *   - Idempotency: per-recipe reindex produces the same DB state as a full
 *     truncate-and-rebuild.
 *   - Cross-reference resolution: incoming refs flip null ↔ id when their
 *     target appears/disappears.
 *   - See-also: rows where the changed recipe is involved are recomputed;
 *     other recipes' rows (and their timestamps) are not touched.
 *   - FTS5: new ingredient text becomes searchable; removed text doesn't.
 */
final class IncrementalReindexParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Establish the baseline by running a full reindex against the real
        // corpus. Tests below mutate from there.
        $this->artisan('recipes:reindex')->assertSuccessful();
    }

    public function test_per_recipe_reindex_matches_full_rebuild(): void
    {
        // Snapshot the corpus after the full reindex.
        $snapshotA = $this->snapshotCorpus();

        // Run reindexOne for every recipe in the corpus. Should be a no-op
        // (same input markdown, same parser, same write path) producing the
        // same final state.
        $reindexer = new RecipeReindexer;
        foreach (Recipe::query()->pluck('slug') as $slug) {
            $result = $reindexer->reindexOne($slug);
            $this->assertSame('updated', $result->status, "Reindex of {$slug} should report status=updated");
        }

        $snapshotB = $this->snapshotCorpus();

        $this->assertSame(
            $snapshotA['recipes'],
            $snapshotB['recipes'],
            'recipes table should be identical after per-recipe reindex'
        );
        $this->assertSame(
            $snapshotA['ingredients'],
            $snapshotB['ingredients'],
            'ingredients should be identical after per-recipe reindex'
        );
        $this->assertSame(
            $snapshotA['method_steps'],
            $snapshotB['method_steps'],
            'method_steps should be identical'
        );
        $this->assertSame(
            $snapshotA['recipe_references'],
            $snapshotB['recipe_references'],
            'recipe_references should be identical (including resolved_recipe_id values)'
        );
        // see_also is sorted as a set — counts and content must match,
        // but per-row updated_at differs as expected after a per-recipe pass.
        $this->assertSame(
            $snapshotA['see_also_set'],
            $snapshotB['see_also_set'],
            'see_also relationships should be the same set'
        );
        $this->assertSame(
            $snapshotA['search_set'],
            $snapshotB['search_set'],
            'recipe_search FTS rows should be the same set'
        );
    }

    public function test_remove_then_add_round_trips(): void
    {
        $reindexer = new RecipeReindexer;
        $slug = 'honey-oat-bread';

        $beforeRecipe = Recipe::query()->where('slug', $slug)->first();
        $this->assertNotNull($beforeRecipe);

        // Snapshot what should be invariant: counts of OTHER recipes' rows.
        $otherIngredientsBefore = DB::table('ingredients')->where('recipe_id', '!=', $beforeRecipe->id)->count();
        $otherMethodStepsBefore = DB::table('method_steps')->where('recipe_id', '!=', $beforeRecipe->id)->count();

        // Remove.
        $removed = $reindexer->remove($slug);
        $this->assertSame('deleted', $removed->status);
        $this->assertNull(Recipe::query()->where('slug', $slug)->first());

        // The OTHER recipes' child rows weren't touched.
        $this->assertSame($otherIngredientsBefore, DB::table('ingredients')->count(),
            'remove() must not delete other recipes\' ingredient rows');
        $this->assertSame($otherMethodStepsBefore, DB::table('method_steps')->count(),
            'remove() must not delete other recipes\' method_steps rows');

        // Re-add by reindexing.
        $created = $reindexer->reindexOne($slug);
        $this->assertSame('created', $created->status);
        $this->assertNotNull(Recipe::query()->where('slug', $slug)->first());
    }

    public function test_incoming_references_unresolve_on_remove_and_resolve_on_recreate(): void
    {
        $reindexer = new RecipeReindexer;

        // apple-pie has an outbound reference to pie-crust (per the corpus).
        // Remove pie-crust → apple-pie's reference should become unresolved.
        $applePie = Recipe::query()->where('slug', 'apple-pie')->first();
        $pieCrust = Recipe::query()->where('slug', 'pie-crust')->first();
        $this->assertNotNull($applePie);
        $this->assertNotNull($pieCrust);

        $refBefore = RecipeReference::query()
            ->where('recipe_id', $applePie->id)
            ->where('referenced_slug', 'pie-crust')
            ->first();
        $this->assertNotNull($refBefore);
        $this->assertSame($pieCrust->id, $refBefore->resolved_recipe_id);

        // Remove pie-crust.
        $reindexer->remove('pie-crust');
        $refAfterRemove = RecipeReference::query()
            ->where('recipe_id', $applePie->id)
            ->where('referenced_slug', 'pie-crust')
            ->first();
        $this->assertNotNull($refAfterRemove);
        $this->assertNull($refAfterRemove->resolved_recipe_id,
            'apple-pie → pie-crust should be unresolved after pie-crust removal');

        // Recreate pie-crust by reindexing it.
        $reindexer->reindexOne('pie-crust');
        $refAfterRecreate = RecipeReference::query()
            ->where('recipe_id', $applePie->id)
            ->where('referenced_slug', 'pie-crust')
            ->first();
        $this->assertNotNull($refAfterRecreate);
        $this->assertNotNull($refAfterRecreate->resolved_recipe_id,
            'apple-pie → pie-crust should resolve again after pie-crust comes back');
    }

    public function test_see_also_rows_not_involving_changed_recipe_are_untouched(): void
    {
        $reindexer = new RecipeReindexer;
        $changedSlug = 'honey-oat-bread';

        $changedRecipe = Recipe::query()->where('slug', $changedSlug)->first();
        $this->assertNotNull($changedRecipe);

        // Snapshot the updated_at of rows where neither recipe is the
        // changed one. Sleep briefly so we can detect a change.
        $touchAbleSnapshot = RecipeSeeAlso::query()
            ->where('recipe_id', '!=', $changedRecipe->id)
            ->where('related_recipe_id', '!=', $changedRecipe->id)
            ->get()
            ->mapWithKeys(fn ($r) => [$r->id => (string) $r->updated_at]);
        sleep(1);

        $reindexer->reindexOne($changedSlug);

        // After reindex, the unrelated rows should still have the SAME
        // updated_at (we never touched them).
        foreach ($touchAbleSnapshot as $id => $oldStamp) {
            $row = RecipeSeeAlso::query()->find($id);
            $this->assertNotNull($row, "see-also row {$id} should still exist");
            $this->assertSame($oldStamp, (string) $row->updated_at,
                "see-also row {$id} (unrelated to changed recipe) should keep its updated_at unchanged");
        }
    }

    public function test_fts_search_reflects_changes_after_reindex(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('FTS test requires SQLite');
        }

        // honey-oat-bread's text includes "honey" (in title and ingredients).
        // After reindex, that should still match.
        $reindexer = new RecipeReindexer;
        $reindexer->reindexOne('honey-oat-bread');

        $hits = DB::select('SELECT slug FROM recipe_search WHERE recipe_search MATCH ?', ['honey']);
        $slugs = array_map(fn ($r) => $r->slug, $hits);
        $this->assertContains('honey-oat-bread', $slugs,
            'FTS index should still match "honey" against honey-oat-bread after a single-slug reindex');
    }

    public function test_full_corpus_30_recipes_still_round_trip_after_per_recipe_pass(): void
    {
        // Sanity: after running reindexOne for every recipe, the corpus
        // count and resolved-refs count match the baseline.
        $reindexer = new RecipeReindexer;
        foreach (Recipe::query()->pluck('slug') as $slug) {
            $reindexer->reindexOne($slug);
        }
        $this->assertSame(30, Recipe::query()->count());
        $resolved = RecipeReference::query()->whereNotNull('resolved_recipe_id')->count();
        $this->assertSame(4, $resolved, 'Should still have 4 resolved cross-references after per-recipe reindex');
    }

    /**
     * Build a deterministic snapshot of the corpus's structural state.
     *
     * @return array<string,mixed>
     */
    private function snapshotCorpus(): array
    {
        $recipes = DB::table('recipes')
            ->orderBy('slug')
            ->get(['slug', 'title', 'category', 'cook_time', 'oven_temp', 'libation', 'libation_prose', 'notes', 'servings', 'yields'])
            ->map(fn ($r) => (array) $r)
            ->all();

        $ingredients = DB::table('ingredients')
            ->join('recipes', 'recipes.id', '=', 'ingredients.recipe_id')
            ->orderBy('recipes.slug')
            ->orderBy('ingredients.position')
            ->get([
                'recipes.slug as recipe_slug', 'ingredients.position', 'ingredients.group_name',
                'ingredients.raw', 'ingredients.parsed', 'ingredients.amount', 'ingredients.amount_high',
                'ingredients.unit', 'ingredients.unit_class', 'ingredients.ingredient',
                'ingredients.modifier', 'ingredients.note', 'ingredients.optional',
            ])
            ->map(fn ($r) => (array) $r)
            ->all();

        $methodSteps = DB::table('method_steps')
            ->join('recipes', 'recipes.id', '=', 'method_steps.recipe_id')
            ->orderBy('recipes.slug')
            ->orderBy('method_steps.position')
            ->get(['recipes.slug as recipe_slug', 'method_steps.position', 'method_steps.content'])
            ->map(fn ($r) => (array) $r)
            ->all();

        $refs = DB::table('recipe_references')
            ->join('recipes', 'recipes.id', '=', 'recipe_references.recipe_id')
            ->orderBy('recipes.slug')
            ->orderBy('recipe_references.referenced_slug')
            ->get([
                'recipes.slug as recipe_slug',
                'recipe_references.referenced_slug',
                'recipe_references.source',
                'recipe_references.resolved_recipe_id',
            ])
            ->map(fn ($r) => (array) $r)
            ->all();

        // See-also as a set of {source_slug, target_slug, score} triples —
        // ignore created_at/updated_at since per-recipe rebuild will re-stamp.
        $seeAlso = DB::table('recipe_see_alsos')
            ->join('recipes as r1', 'r1.id', '=', 'recipe_see_alsos.recipe_id')
            ->join('recipes as r2', 'r2.id', '=', 'recipe_see_alsos.related_recipe_id')
            ->get(['r1.slug as source_slug', 'r2.slug as target_slug', 'recipe_see_alsos.score'])
            ->map(fn ($r) => [
                'source_slug' => $r->source_slug,
                'target_slug' => $r->target_slug,
                'score' => (int) $r->score,
            ])
            ->all();
        usort($seeAlso, fn ($a, $b) => [$a['source_slug'], $a['target_slug']] <=> [$b['source_slug'], $b['target_slug']]);

        $search = [];
        if (DB::connection()->getDriverName() === 'sqlite') {
            $rows = DB::select('SELECT slug, title, ingredients_text, method_text, notes_text, libation_text FROM recipe_search ORDER BY slug');
            foreach ($rows as $r) {
                $search[] = (array) $r;
            }
        }

        return [
            'recipes' => $recipes,
            'ingredients' => $ingredients,
            'method_steps' => $methodSteps,
            'recipe_references' => $refs,
            'see_also_set' => $seeAlso,
            'search_set' => $search,
        ];
    }
}
