<?php

declare(strict_types=1);

namespace Tests\Feature;

final class SearchPageTest extends IndexedCorpusTestCase
{
    public function test_landing_page_renders_when_no_query_or_filters(): void
    {
        $response = $this->get('/search');
        $response->assertStatus(200);
        $response->assertSee('Search recipes…', escape: false);
        // Suggested terms surface on the landing.
        $response->assertSee('bread');
        $response->assertSee('garlic');
    }

    public function test_query_returns_results_page(): void
    {
        $response = $this->get('/search?q=butter');
        $response->assertStatus(200);
        $response->assertSee('Results for', escape: false);
        $response->assertSee('"butter"', escape: false);
        // Honey Oat Bread should appear since it has 2 Tbsp butter.
        $response->assertSee('Honey Oat Bread');
    }

    public function test_empty_state_for_no_matches(): void
    {
        $response = $this->get('/search?q=zzznomatchanywhere');
        $response->assertStatus(200);
        $response->assertSee('No recipes match', escape: false);
        // Suggested terms appear in the empty-state fallback.
        $response->assertSee('Or try', escape: false);
    }

    public function test_category_filter_alone_shows_filtered_recipes(): void
    {
        $response = $this->get('/search?category[]=breads');
        $response->assertStatus(200);
        $response->assertSee('Honey Oat Bread');
        $response->assertSee('Big Soft Pretzels');
        // Filter pill visible.
        $response->assertSee('category: breads');
    }

    public function test_global_search_box_is_present_in_layout(): void
    {
        $response = $this->get('/');
        $response->assertSee('id="global-search-input"', escape: false);
        // The Alpine keyboard handler is wired up.
        $response->assertSee('@keydown.window.slash', escape: false);
    }

    public function test_phrase_query_finds_phrase_recipe(): void
    {
        $response = $this->get('/search?'.http_build_query(['q' => '"no knead"']));
        $response->assertStatus(200);
        $response->assertSee('Classic No-Knead Artisan Bread');
    }
}
