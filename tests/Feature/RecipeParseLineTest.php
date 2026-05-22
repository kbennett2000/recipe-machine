<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IngredientLlmCache;

/**
 * Phase 11H — parse-line endpoint tests.
 *
 * The editor's "Convert to structured" button on an unparsed line posts
 * the raw text here. The endpoint tries the rules-based parser, then
 * looks up the LLM cache (no live API call), then falls back to a
 * best-effort row with `source=fallback`.
 */
final class RecipeParseLineTest extends IndexedCorpusTestCase
{
    private const TEST_SLUG = 'honey-oat-bread';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    }

    private function url(): string
    {
        return '/recipes/'.self::TEST_SLUG.'/edit/parse-line';
    }

    public function test_rules_parsable_line_returns_source_rules(): void
    {
        $response = $this->postJson($this->url(), ['line' => '3 cups flour']);
        $response->assertStatus(200);
        $response->assertJson([
            'parsed' => true,
            'amount' => 3,
            'unit' => 'cup',
            'ingredient' => 'flour',
            'source' => 'rules',
        ]);
    }

    public function test_llm_cached_line_returns_source_llm(): void
    {
        // Seed the LLM cache with a parse that the rules engine cannot
        // structure — a parenthetical-only line.
        $rawLine = 'Coarse pretzel salt (or kosher salt)';
        IngredientLlmCache::create([
            'raw_line' => $rawLine,
            'raw_line_hash' => IngredientLlmCache::hashFor($rawLine),
            'status' => 'hit',
            'parsed_payload' => [
                'amount' => null,
                'amount_high' => null,
                'unit' => null,
                'ingredient' => 'coarse pretzel salt',
                'modifier' => null,
                'note' => 'or kosher salt',
                'optional' => false,
            ],
            'model_used' => 'claude-test',
        ]);

        $response = $this->postJson($this->url(), ['line' => $rawLine]);
        $response->assertStatus(200);
        $response->assertJson([
            'parsed' => true,
            'ingredient' => 'coarse pretzel salt',
            'note' => 'or kosher salt',
            'source' => 'llm',
        ]);
    }

    public function test_unparseable_line_returns_fallback(): void
    {
        $response = $this->postJson($this->url(), ['line' => 'complete gibberish nonsense xyzzy']);
        $response->assertStatus(200);
        $response->assertJson([
            'parsed' => false,
            'ingredient' => 'complete gibberish nonsense xyzzy',
            'source' => 'fallback',
        ]);
    }

    public function test_empty_body_returns_422(): void
    {
        $this->postJson($this->url(), [])->assertStatus(422);
    }

    public function test_blank_line_returns_422(): void
    {
        // The validator requires a non-empty string; whitespace-only goes
        // through validation, gets trimmed in the controller, and trips
        // the explicit empty check.
        $this->postJson($this->url(), ['line' => '   '])->assertStatus(422);
    }

    public function test_endpoint_returns_404_for_unknown_recipe(): void
    {
        $this->postJson('/recipes/no-such-recipe/edit/parse-line', ['line' => '1 cup sugar'])
            ->assertStatus(404);
    }

    public function test_cache_miss_does_not_become_llm_source(): void
    {
        // A 'miss' tombstone in the cache should NOT be treated as a hit —
        // the endpoint should fall through to the fallback path.
        $rawLine = 'something the llm declined to parse';
        IngredientLlmCache::create([
            'raw_line' => $rawLine,
            'raw_line_hash' => IngredientLlmCache::hashFor($rawLine),
            'status' => 'miss',
            'parsed_payload' => null,
            'model_used' => null,
            'expires_at' => now()->addDays(30),
        ]);

        $response = $this->postJson($this->url(), ['line' => $rawLine]);
        $response->assertStatus(200);
        $response->assertJson([
            'parsed' => false,
            'source' => 'fallback',
        ]);
    }
}
