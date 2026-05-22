<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\Ingredient;
use App\Models\MethodStep;
use App\Models\Recipe;
use App\Recipes\Search\RecipeSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RecipeSearch service tests.
 *
 * Per the Phase 4 brief this lives under tests/Unit/, but the search service
 * is inseparable from SQLite FTS5 — these tests have to run against a real DB.
 * The class still extends Tests\TestCase (which boots Laravel + DB) rather
 * than the bare PHPUnit base. setUp() reindexes the production corpus into
 * the in-memory DB once per test.
 */
final class RecipeSearchTest extends TestCase
{
    use RefreshDatabase;

    private RecipeSearch $search;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('recipes:reindex')->assertSuccessful();
        $this->search = new RecipeSearch;
    }

    public function test_simple_query_returns_results(): void
    {
        $results = $this->search->query('butter');
        $this->assertGreaterThan(0, $results->count(), 'A search for "butter" should find recipes.');
        $slugs = $results->slugs();
        $this->assertContains('honey-oat-bread', $slugs, 'Honey Oat Bread has 2 Tbsp butter.');
    }

    public function test_title_match_outranks_body_match(): void
    {
        $results = $this->search->query('honey oat');
        $slugs = $results->slugs();
        $this->assertSame('honey-oat-bread', $slugs[0],
            'Honey Oat Bread should rank #1 for "honey oat" because the title matches both terms.');
    }

    public function test_stemming_via_porter_tokenizer(): void
    {
        // "knead" should match content containing "kneading" / "kneads".
        $results = $this->search->query('knead');
        $this->assertGreaterThan(0, $results->count(),
            'Porter stemming should make "knead" match recipes that say "kneading".');
        // No-Knead Artisan Bread has it in the title.
        $this->assertContains('classic-no-knead-artisan-bread', $results->slugs());
    }

    public function test_diacritics_normalized_by_remove_diacritics_2(): void
    {
        // Inject a synthetic recipe with a diacritic so we can test the round-trip.
        // (The production corpus doesn't currently have any diacritic content.)
        $recipe = Recipe::create([
            'slug' => 'creme-brulee-test',
            'title' => 'Crème Brûlée',
            'category' => 'desserts',
            'source_path' => 'recipes/desserts/creme-brulee-test.md',
            'parsed_at' => now(),
        ]);
        DB::insert(
            'INSERT INTO recipe_search (slug, title, ingredients_text, method_text, notes_text, libation_text) VALUES (?, ?, ?, ?, ?, ?)',
            [$recipe->slug, $recipe->title, 'crème, sucre', '', '', '']
        );

        $hits = $this->search->query('creme')->slugs();
        $this->assertContains('creme-brulee-test', $hits,
            'remove_diacritics 2 should make "creme" match indexed "crème".');
    }

    public function test_phrase_query_matches_exact_sequence(): void
    {
        // "no knead" as a phrase should land Classic No-Knead Artisan Bread first.
        $results = $this->search->query('"no knead"');
        $this->assertGreaterThan(0, $results->count());
        $this->assertContains('classic-no-knead-artisan-bread', $results->slugs());
    }

    public function test_category_filter_alone_returns_all_in_category(): void
    {
        $results = $this->search->query('', ['category' => 'breads']);
        $this->assertSame(15, $results->count(), 'All 15 breads should match the category filter alone.');
    }

    public function test_query_combined_with_category_filter_narrows(): void
    {
        // "butter" matches many recipes across categories; restricting to soups narrows to potato-soup.
        $results = $this->search->query('butter', ['category' => 'soups']);
        $this->assertSame(['potato-soup'], $results->slugs());
    }

    public function test_empty_query_with_category_filter_sorts_alphabetically(): void
    {
        // Pull the canonical "all desserts sorted by title" list straight
        // from the DB and assert the search service returns the same. This
        // tests alphabetical-sort behavior without hard-coding which
        // recipes are in the desserts category — adding a new dessert
        // (which the v1.1 web editor makes a 30-second task) shouldn't
        // break this assertion.
        $expected = \App\Models\Recipe::query()
            ->where('category', 'desserts')
            ->orderBy('title')
            ->pluck('slug')
            ->all();
        $this->assertNotEmpty($expected, 'Test fixture: desserts category should have recipes.');

        $slugs = $this->search->query('', ['category' => 'desserts'])->slugs();
        $this->assertSame($expected, $slugs);
    }

    public function test_no_match_returns_empty_results(): void
    {
        $results = $this->search->query('zzznomatchatall');
        $this->assertTrue($results->isEmpty());
    }

    public function test_results_contain_snippets_with_mark_highlights(): void
    {
        $results = $this->search->query('honey');
        $hit = $results->results[0];
        $this->assertNotEmpty($hit->snippets, 'Top result should have at least one snippet.');
        $firstKey = array_key_first($hit->snippets);
        $first = $hit->snippets[$firstKey];
        $this->assertStringContainsString('<mark>', $first);
        $this->assertStringContainsString('</mark>', $first);
    }
}
