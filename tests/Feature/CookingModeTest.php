<?php

declare(strict_types=1);

namespace Tests\Feature;

final class CookingModeTest extends IndexedCorpusTestCase
{
    public function test_cook_page_loads_for_recipe_with_method(): void
    {
        $response = $this->get('/recipes/honey-oat-bread/cook');
        $response->assertStatus(200);
        $response->assertSee('Honey Oat Bread');
        $response->assertSee('Step', escape: false);
        $response->assertSee('of', escape: false);
    }

    public function test_cook_page_renders_timer_buttons_in_step_content(): void
    {
        // Honey Oat Bread step 1 contains "rise 1–1½ hours" and "rise 45–60 min"
        // — both should become tappable timer buttons in cooking mode.
        $response = $this->get('/recipes/honey-oat-bread/cook');
        $response->assertStatus(200);
        $body = $response->getContent();
        $this->assertStringContainsString('class="timer-btn"', $body);
        $this->assertStringContainsString('data-seconds=', $body);
        // The range timers should carry the low-bound attribute too.
        $this->assertStringContainsString('data-seconds-low=', $body);
        // And the @click should call startTimer with three args (label, secs, low).
        $this->assertStringContainsString('startTimer(', $body);
    }

    public function test_cook_page_renders_temperature_highlights(): void
    {
        // Step 2 of honey-oat-bread mentions "350°F".
        $response = $this->get('/recipes/honey-oat-bread/cook');
        $response->assertStatus(200);
        $response->assertSee('metric-temp', escape: false);
    }

    public function test_cook_page_includes_ingredient_data_for_scaling_parity(): void
    {
        // The cooking-mode sidebar mirrors the show-page sidebar's data-*
        // attributes so the scaling logic could be wired up later — confirm
        // at least one ingredient renders with data-amount + data-ingredient.
        $response = $this->get('/recipes/honey-oat-bread/cook');
        $body = $response->getContent();
        $this->assertStringContainsString('data-amount=', $body);
        $this->assertStringContainsString('data-ingredient=', $body);
    }

    public function test_cook_page_clamps_out_of_range_step_query(): void
    {
        // Out-of-range step should clamp into the valid 1..N range; we just
        // need to assert the page still renders (the clamp logic is exercised
        // server-side, the resulting startStep is embedded in cookingMode(...)).
        $response = $this->get('/recipes/honey-oat-bread/cook?step=999');
        $response->assertStatus(200);
        // The Alpine init call should embed a clamped integer (not 999).
        $body = $response->getContent();
        // Phase 7.1 added a 4th arg (defaultServings). Match the 3rd arg
        // robustly with [^,)]+ separators.
        $this->assertDoesNotMatchRegularExpression('/cookingMode\([^,)]+,\s*\d+,\s*999[,)]/', $body);
    }

    public function test_cook_page_negative_step_is_clamped_to_one(): void
    {
        $response = $this->get('/recipes/honey-oat-bread/cook?step=-5');
        $response->assertStatus(200);
        $body = $response->getContent();
        // The third arg to cookingMode(...) is startStep; should be 1, not -5.
        // Phase 7.1 added a 4th arg, so accept either ", 1)" or ", 1, …)".
        $this->assertMatchesRegularExpression('/cookingMode\([^,)]+,\s*\d+,\s*1[,)]/', $body);
    }

    public function test_cook_page_for_zero_method_recipe_shows_placeholder(): void
    {
        // pasta-sauce has zero method steps — cooking mode should render
        // gracefully with a pointer back to the recipe page, not 404.
        $response = $this->get('/recipes/pasta-sauce/cook');
        $response->assertStatus(200);
        $response->assertSee('No instructions recorded');
    }

    public function test_cook_page_404_for_unknown_recipe(): void
    {
        $this->get('/recipes/no-such-recipe-anywhere/cook')->assertStatus(404);
    }

    public function test_recipe_show_page_has_cook_button_when_method_exists(): void
    {
        $response = $this->get('/recipes/honey-oat-bread');
        $response->assertStatus(200);
        $response->assertSee(route('recipes.cook', ['recipe' => 'honey-oat-bread']));
        // The button label "Cook" should be present in the rendered HTML.
        $this->assertMatchesRegularExpression(
            '/href="[^"]*\/cook"[^>]*>\s*<span[^>]*>[^<]*<\/span>\s*Cook/',
            $response->getContent(),
        );
    }

    public function test_recipe_show_page_omits_cook_button_when_no_method(): void
    {
        $response = $this->get('/recipes/pasta-sauce');
        $response->assertStatus(200);
        // The Cook button link should not appear when there are no method steps.
        $body = $response->getContent();
        $this->assertStringNotContainsString(
            route('recipes.cook', ['recipe' => 'pasta-sauce']),
            $body,
        );
    }
}
