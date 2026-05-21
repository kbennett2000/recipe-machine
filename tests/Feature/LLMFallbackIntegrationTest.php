<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\IngredientLlmCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 9 — end-to-end: reindex --with-llm picks up a real-corpus unparsed
 * line, the LLM (faked) returns a structured parse, the ingredient row gets
 * updated, llm_parsed flips to true. Then a second reindex --with-llm hits
 * the cache and makes no API call.
 */
final class LLMFallbackIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'recipe-machine.llm.enabled' => true,
            'recipe-machine.llm.api_key' => 'test-key',
            'recipe-machine.llm.model' => 'claude-haiku-4-5-20251001',
            'recipe-machine.llm.batch_size' => 20,
            'recipe-machine.llm.cache_tombstone_ttl_days' => 30,
            'recipe-machine.llm.api_base_url' => 'https://api.anthropic.com',
        ]);
    }

    public function test_reindex_with_llm_parses_unparsed_lines_and_persists(): void
    {
        // First reindex (no LLM) — establishes the baseline of unparsed lines.
        $this->artisan('recipes:reindex')->assertSuccessful();
        $unparsedBefore = Ingredient::where('parsed', false)->count();
        $this->assertGreaterThan(0, $unparsedBefore, 'Corpus must contain at least one unparsed line for this test to be meaningful');

        // Fake an Anthropic response that returns a structured object for
        // EVERY input line. The order of inputs is deterministic since the
        // parser deduplicates and chunks in input order.
        Http::fake(function ($request) {
            $payload = $request->data();
            $userMsg = $payload['messages'][0]['content'] ?? '';
            preg_match('/"lines":\s*(\[.+?\])\s*}/s', $userMsg, $m);
            $count = 1;
            if (isset($m[1])) {
                $arr = json_decode($m[1], true);
                if (is_array($arr)) {
                    $count = count($arr);
                }
            }
            $reply = array_fill(0, $count, [
                'amount' => null, 'amount_high' => null, 'unit' => null,
                'ingredient' => 'auto-parsed ingredient',
                'modifier' => null, 'note' => null, 'optional' => false,
            ]);
            return Http::response([
                'content' => [['type' => 'text', 'text' => json_encode($reply)]],
            ], 200);
        });

        $this->artisan('recipes:reindex', ['--with-llm' => true])->assertSuccessful();

        // After --with-llm: there should be at least one row with llm_parsed=true.
        $llmRows = Ingredient::where('llm_parsed', true)->count();
        $this->assertGreaterThan(0, $llmRows, 'Expected at least one ingredient row marked llm_parsed=true');
        // And the parsed:true count should have grown.
        $unparsedAfter = Ingredient::where('parsed', false)->count();
        $this->assertLessThan($unparsedBefore, $unparsedAfter, 'LLM should have parsed at least one line');

        // Cache should have hits for those lines.
        $hits = IngredientLlmCache::where('status', 'hit')->count();
        $this->assertGreaterThan(0, $hits);
    }

    public function test_repeat_reindex_with_llm_hits_cache_no_api_calls(): void
    {
        // First run: prime the cache via the same fake from the previous test.
        Http::fake(function ($request) {
            $payload = $request->data();
            $userMsg = $payload['messages'][0]['content'] ?? '';
            preg_match('/"lines":\s*(\[.+?\])\s*}/s', $userMsg, $m);
            $count = isset($m[1]) ? count(json_decode($m[1], true) ?? []) : 1;
            $reply = array_fill(0, $count, [
                'amount' => null, 'amount_high' => null, 'unit' => null,
                'ingredient' => 'auto-parsed ingredient',
                'modifier' => null, 'note' => null, 'optional' => false,
            ]);
            return Http::response([
                'content' => [['type' => 'text', 'text' => json_encode($reply)]],
            ], 200);
        });

        $this->artisan('recipes:reindex', ['--with-llm' => true])->assertSuccessful();
        $cacheHitsAfterFirst = IngredientLlmCache::where('status', 'hit')->count();
        $this->assertGreaterThan(0, $cacheHitsAfterFirst);

        // Second run: replace the fake with one that would fail if called.
        Http::fake([
            '*' => Http::response('SHOULD NOT BE CALLED', 500),
        ]);

        $this->artisan('recipes:reindex', ['--with-llm' => true])->assertSuccessful();

        // No new API calls — the cache covered every line.
        Http::assertNothingSent();

        // And the ingredients table still has llm_parsed=true rows after the
        // re-truncate-and-rebuild (because the cache survives reindex).
        $this->assertGreaterThan(0, Ingredient::where('llm_parsed', true)->count());
    }

    public function test_reindex_without_with_llm_flag_skips_fallback(): void
    {
        Http::fake([
            '*' => Http::response('SHOULD NOT BE CALLED', 500),
        ]);

        $this->artisan('recipes:reindex')->assertSuccessful();

        Http::assertNothingSent();
        // No llm-parsed rows because we never invoked the fallback.
        $this->assertSame(0, Ingredient::where('llm_parsed', true)->count());
    }
}
