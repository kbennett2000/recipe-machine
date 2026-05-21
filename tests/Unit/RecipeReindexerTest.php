<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Recipe;
use App\Recipes\Indexing\RecipeReindexer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 11B — RecipeReindexer unit tests.
 *
 * Uses tests/Fixtures/Reindexer/ as the recipe root (a small fixture
 * corpus we control) so we can exercise create/update/remove paths
 * without touching the real recipes/ directory.
 */
final class RecipeReindexerTest extends TestCase
{
    use RefreshDatabase;

    private string $fixtureRoot;

    private RecipeReindexer $reindexer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureRoot = sys_get_temp_dir().'/recipe-reindexer-test-'.uniqid('', true);
        mkdir($this->fixtureRoot.'/breads', 0777, true);
        $this->reindexer = new RecipeReindexer;
    }

    protected function tearDown(): void
    {
        if (is_dir($this->fixtureRoot)) {
            $this->rmrf($this->fixtureRoot);
        }
        parent::tearDown();
    }

    public function test_reindex_one_returns_not_found_when_file_missing(): void
    {
        $result = $this->reindexer->reindexOne('does-not-exist', $this->fixtureRoot);
        $this->assertSame('not_found', $result->status);
        $this->assertSame('does-not-exist', $result->slug);
        $this->assertSame([], $result->changes);
        // elapsed_ms is populated even on the fast-path failure.
        $this->assertGreaterThanOrEqual(0, $result->elapsedMs);
    }

    public function test_reindex_one_returns_created_for_a_new_recipe(): void
    {
        $this->writeRecipe('breads/my-bread.md', $this->minimalRecipe('My Bread', 'my-bread', 'breads'));
        $result = $this->reindexer->reindexOne('my-bread', $this->fixtureRoot);

        $this->assertSame('created', $result->status);
        $this->assertSame(2, $result->changes['ingredients']);
        $this->assertSame(1, $result->changes['method_steps']);
        $this->assertNotNull(Recipe::query()->where('slug', 'my-bread')->first());
    }

    public function test_reindex_one_returns_updated_for_an_existing_recipe(): void
    {
        $this->writeRecipe('breads/my-bread.md', $this->minimalRecipe('My Bread', 'my-bread', 'breads'));
        $first = $this->reindexer->reindexOne('my-bread', $this->fixtureRoot);
        $this->assertSame('created', $first->status);

        $second = $this->reindexer->reindexOne('my-bread', $this->fixtureRoot);
        $this->assertSame('updated', $second->status);
    }

    public function test_remove_returns_deleted_for_an_existing_recipe(): void
    {
        $this->writeRecipe('breads/my-bread.md', $this->minimalRecipe('My Bread', 'my-bread', 'breads'));
        $this->reindexer->reindexOne('my-bread', $this->fixtureRoot);

        $result = $this->reindexer->remove('my-bread');
        $this->assertSame('deleted', $result->status);
        $this->assertNull(Recipe::query()->where('slug', 'my-bread')->first());
    }

    public function test_remove_returns_not_found_when_recipe_not_in_db(): void
    {
        $result = $this->reindexer->remove('never-existed');
        $this->assertSame('not_found', $result->status);
    }

    public function test_elapsed_ms_is_populated_on_success(): void
    {
        $this->writeRecipe('breads/elapsed-test.md', $this->minimalRecipe('Elapsed Test', 'elapsed-test', 'breads'));
        $result = $this->reindexer->reindexOne('elapsed-test', $this->fixtureRoot);
        $this->assertGreaterThanOrEqual(0, $result->elapsedMs);
        $this->assertLessThan(2000, $result->elapsedMs, 'Reindex should not take 2 seconds on a fixture corpus');
    }

    public function test_find_recipe_file_locates_by_filename(): void
    {
        $this->writeRecipe('breads/locate-me.md', $this->minimalRecipe('Locate Me', 'locate-me', 'breads'));
        $path = $this->reindexer->findRecipeFile('locate-me', $this->fixtureRoot);
        $this->assertNotNull($path);
        $this->assertStringEndsWith('locate-me.md', $path);
    }

    public function test_find_recipe_file_returns_null_when_missing(): void
    {
        $path = $this->reindexer->findRecipeFile('nope', $this->fixtureRoot);
        $this->assertNull($path);
    }

    public function test_reindex_replaces_existing_child_rows(): void
    {
        // Initial reindex with 2 ingredients.
        $this->writeRecipe('breads/replace.md', $this->minimalRecipe('Replace Me', 'replace', 'breads'));
        $this->reindexer->reindexOne('replace', $this->fixtureRoot);
        $recipe = Recipe::query()->where('slug', 'replace')->first();
        $this->assertSame(2, $recipe->ingredients()->count());

        // Update the file with 4 ingredients and reindex.
        $this->writeRecipe('breads/replace.md', $this->fourIngredientRecipe('Replace Me', 'replace', 'breads'));
        $this->reindexer->reindexOne('replace', $this->fixtureRoot);
        $recipe->refresh();
        $this->assertSame(4, $recipe->ingredients()->count());
    }

    /**
     * Build a minimal valid recipe markdown string.
     */
    private function minimalRecipe(string $title, string $slug, string $category): string
    {
        return <<<MD
---
title: {$title}
category: {$category}
slug: {$slug}
---

## Ingredients

- 2 cups flour
- 1 tsp salt

## Method

1. Mix and bake.
MD;
    }

    private function fourIngredientRecipe(string $title, string $slug, string $category): string
    {
        return <<<MD
---
title: {$title}
category: {$category}
slug: {$slug}
---

## Ingredients

- 2 cups flour
- 1 tsp salt
- 1 tbsp sugar
- 1 cup water

## Method

1. Mix and bake.
MD;
    }

    private function writeRecipe(string $relativePath, string $content): void
    {
        $full = $this->fixtureRoot.'/'.$relativePath;
        $dir = dirname($full);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($full, $content);
    }

    private function rmrf(string $path): void
    {
        if (! is_dir($path)) {
            @unlink($path);
            return;
        }
        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->rmrf($path.'/'.$item);
        }
        rmdir($path);
    }
}
