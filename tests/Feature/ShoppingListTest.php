<?php

declare(strict_types=1);

namespace Tests\Feature;

final class ShoppingListTest extends IndexedCorpusTestCase
{
    public function test_get_shopping_list_renders_empty_state_shell(): void
    {
        $response = $this->get('/shopping-list');
        $response->assertStatus(200);
        // The shell ships with the empty-state copy — Alpine flips it on/off
        // based on sessionStorage. We just verify the shell rendered.
        $response->assertSee('Your shopping list is empty', escape: false);
        $response->assertSee('shoppingListPage', escape: false);
    }

    public function test_calculate_returns_aggregated_list_for_two_recipes(): void
    {
        $response = $this->postJson('/shopping-list/calculate', [
            'recipes' => [
                ['slug' => 'honey-oat-bread', 'scale' => 1.0],
                ['slug' => 'potato-soup', 'scale' => 1.0],
            ],
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'by_aisle',
            'unparsed',
            'source_recipes' => [
                ['slug', 'title', 'scale'],
            ],
            'total_line_count',
        ]);

        $data = $response->json();
        $this->assertSame(2, count($data['source_recipes']));
        $this->assertGreaterThan(0, $data['total_line_count']);

        // Each recipe's distinctive ingredients land in an aisle.
        // Honey Oat Bread contributes flour (Pantry) and butter (Dairy).
        $pantry = $data['by_aisle']['Pantry'] ?? [];
        $dairy = $data['by_aisle']['Dairy'] ?? [];
        $this->assertNotNull(collect($pantry)->firstWhere('name', 'Flour'),
            'Honey Oat Bread\'s flour should appear in the Pantry aisle.');
        $this->assertNotNull(collect($dairy)->firstWhere('name', 'Butter'),
            'Honey Oat Bread\'s butter should appear in the Dairy aisle.');

        // Potato Soup contributes garlic cloves (Produce) and bacon (Meat & Seafood).
        $produce = $data['by_aisle']['Produce'] ?? [];
        $meat = $data['by_aisle']['Meat & Seafood'] ?? [];
        $this->assertNotNull(collect($produce)->firstWhere('name', 'Garlic Cloves'),
            'Potato Soup\'s garlic cloves should appear in Produce.');
        $this->assertNotEmpty($meat, 'Meat & Seafood aisle should be populated by Potato Soup\'s bacon.');
    }

    public function test_calculate_returns_unparsed_lines_with_source(): void
    {
        // Potato Soup has at least one unparsed line ("chives, extra shredded...").
        $response = $this->postJson('/shopping-list/calculate', [
            'recipes' => [['slug' => 'potato-soup', 'scale' => 1.0]],
        ]);

        $data = $response->json();
        $this->assertNotEmpty($data['unparsed']);
        $this->assertSame('potato-soup', $data['unparsed'][0]['source_slug']);
        $this->assertSame('Potato Soup', $data['unparsed'][0]['source_title']);
    }

    public function test_calculate_silently_skips_nonexistent_slug(): void
    {
        // Mixed payload: one real, one bogus. The aggregator handles the
        // missing slug by skipping; the request still returns 200.
        $response = $this->postJson('/shopping-list/calculate', [
            'recipes' => [
                ['slug' => 'honey-oat-bread', 'scale' => 1.0],
                ['slug' => 'does-not-exist', 'scale' => 1.0],
            ],
        ]);
        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data['source_recipes']);
        $this->assertSame('honey-oat-bread', $data['source_recipes'][0]['slug']);
    }

    public function test_calculate_empty_payload_returns_empty_aggregation(): void
    {
        $response = $this->postJson('/shopping-list/calculate', ['recipes' => []]);
        $response->assertStatus(200);
        $data = $response->json();
        $this->assertSame([], $data['by_aisle']);
        $this->assertSame(0, $data['total_line_count']);
    }

    public function test_calculate_honors_scale_factor(): void
    {
        $response1x = $this->postJson('/shopping-list/calculate', [
            'recipes' => [['slug' => 'honey-oat-bread', 'scale' => 1.0]],
        ]);
        $response2x = $this->postJson('/shopping-list/calculate', [
            'recipes' => [['slug' => 'honey-oat-bread', 'scale' => 2.0]],
        ]);

        $flour1 = collect($response1x->json('by_aisle.Pantry') ?? [])->firstWhere('name', 'Flour');
        $flour2 = collect($response2x->json('by_aisle.Pantry') ?? [])->firstWhere('name', 'Flour');
        $this->assertNotNull($flour1);
        $this->assertNotNull($flour2);
        $this->assertEqualsWithDelta(
            $flour1['quantities'][0]['amount'] * 2,
            $flour2['quantities'][0]['amount'],
            0.01,
            '2x scale should double the flour amount.',
        );
    }

    public function test_recipe_page_has_add_to_shopping_list_button(): void
    {
        $response = $this->get('/recipes/honey-oat-bread');
        $response->assertStatus(200);
        $response->assertSee('Add to shopping list', escape: false);
    }

    public function test_nav_includes_shopping_list_link(): void
    {
        $response = $this->get('/');
        $response->assertSee(route('shopping-list'));
    }
}
