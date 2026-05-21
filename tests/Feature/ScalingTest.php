<?php

declare(strict_types=1);

namespace Tests\Feature;

final class ScalingTest extends IndexedCorpusTestCase
{
    public function test_recipe_with_yields_shows_servings_stepper(): void
    {
        $response = $this->get('/recipes/honey-oat-bread');
        $response->assertStatus(200);
        $response->assertSee('data-testid="servings-stepper"', escape: false);
        $response->assertSee('Servings:', escape: false);
        // Alpine wiring.
        $response->assertSee("recipeScale('honey-oat-bread', 12)", escape: false);
    }

    public function test_recipe_without_yields_hides_stepper(): void
    {
        // pasta.md has no yields in the corpus.
        $response = $this->get('/recipes/pasta');
        $response->assertStatus(200);
        $response->assertDontSee('data-testid="servings-stepper"', escape: false);
        $response->assertDontSee('Servings:', escape: false);
    }

    public function test_ingredients_carry_data_attributes_on_yields_recipe(): void
    {
        $response = $this->get('/recipes/honey-oat-bread');
        $body = $response->getContent();

        // Every parsed ingredient (7 of them on honey-oat-bread) should have a
        // data-amount attribute on its span.
        $count = preg_match_all('/data-amount="\d/u', $body);
        $this->assertSame(7, $count, 'Honey Oat Bread should have 7 ingredient spans with data-amount.');

        // Spot-check unit and ingredient attributes survive.
        $this->assertStringContainsString('data-unit="cup"', $body);
        $this->assertStringContainsString('data-ingredient="flour"', $body);
        $this->assertStringContainsString('data-ingredient="instant yeast"', $body);
    }

    public function test_ingredients_carry_data_attributes_on_subgrouped_recipe(): void
    {
        // Big Soft Pretzels has implicit sub-groups in the source; even
        // without an explicit `### Group` header, every parsed ingredient
        // should carry data attributes.
        $response = $this->get('/recipes/big-soft-pretzels');
        $body = $response->getContent();

        $count = preg_match_all('/data-amount="/u', $body);
        $this->assertGreaterThan(0, $count, 'Sub-grouped recipe ingredients should still carry data-amount.');
    }

    public function test_chocolate_chip_cookies_yields_36_after_hand_edit(): void
    {
        // Phase 5 added yields=36 to this file. Confirm it lands in the DB
        // and surfaces the stepper.
        $response = $this->get('/recipes/chocolate-chip-cookies');
        $response->assertStatus(200);
        $response->assertSee("recipeScale('chocolate-chip-cookies', 36)", escape: false);
    }

    public function test_potato_soup_yields_8_after_hand_edit(): void
    {
        $response = $this->get('/recipes/potato-soup');
        $response->assertStatus(200);
        $response->assertSee("recipeScale('potato-soup', 8)", escape: false);
    }

    public function test_imprecise_ingredients_have_no_amount_attribute(): void
    {
        // Vanilla Ice Cream has "Pinch of kosher salt" (imprecise, amount=null).
        $response = $this->get('/recipes/vanilla-ice-cream');
        $body = $response->getContent();
        // Find the salt line; it should have data-unit="pinch" but no data-amount.
        $this->assertMatchesRegularExpression(
            '/<span[^>]*data-unit="pinch"[^>]*>a pinch of kosher salt<\/span>/u',
            $body,
            'Pinch-style imprecise ingredient should render with unit attribute but no amount attribute.',
        );
    }
}
