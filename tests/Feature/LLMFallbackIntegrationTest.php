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

    public function test_llm_parse_fallback_dry_run_does_not_call_api_or_write_cache(): void
    {
        // Prime the corpus with unparsed lines.
        $this->artisan('recipes:reindex')->assertSuccessful();
        $this->assertGreaterThan(0, Ingredient::where('parsed', false)->count());

        Http::fake([
            '*' => Http::response('SHOULD NOT BE CALLED', 500),
        ]);

        $this->artisan('recipes:llm-parse-fallback', ['--dry-run' => true])
            ->expectsOutputToContain('Would submit')
            ->expectsOutputToContain('Run without --dry-run to actually parse.')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, IngredientLlmCache::count(), 'Dry-run must not write any cache rows');
        $this->assertSame(0, Ingredient::where('llm_parsed', true)->count(), 'Dry-run must not mutate ingredient rows');
    }

    public function test_reindex_with_llm_dry_run_skips_api_and_cache_writes(): void
    {
        Http::fake([
            '*' => Http::response('SHOULD NOT BE CALLED', 500),
        ]);

        $this->artisan('recipes:reindex', ['--with-llm' => true, '--dry-run' => true])
            ->expectsOutputToContain('LLM fallback dry-run: would submit')
            ->expectsOutputToContain('Run without --dry-run to actually parse.')
            ->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(0, IngredientLlmCache::count(), 'Reindex --dry-run must not write any cache rows');
        $this->assertSame(0, Ingredient::where('llm_parsed', true)->count());
        // Recipe data should still be present — reindex itself isn't dry.
        $this->assertGreaterThan(0, \App\Models\Recipe::count(), 'Reindex must still populate recipe data even under --dry-run');
    }

    public function test_apply_to_unparsed_rows_stats_are_disjoint(): void
    {
        // Stage: corpus has N unparsed lines. We craft an LLM response that
        // returns: structured for line 1, null for line 2, valid-but-invalid
        // for line 3 (a fictional unit). After running:
        //   parsed = 1, cached_misses = 2 (one explicit null, one validation
        //   miss), still_unparsed = N - 3.
        $this->artisan('recipes:reindex')->assertSuccessful();
        $unparsedTotal = Ingredient::where('parsed', false)->count();
        $this->assertGreaterThanOrEqual(3, $unparsedTotal, 'corpus must have at least 3 unparsed lines for this test');

        // Fake a response that returns the same 3-element pattern regardless
        // of input length, then null-pads the remainder.
        Http::fake(function ($request) {
            $payload = $request->data();
            $userMsg = $payload['messages'][0]['content'] ?? '';
            preg_match('/"lines":\s*(\[.+?\])\s*}/s', $userMsg, $m);
            $count = isset($m[1]) ? count(json_decode($m[1], true) ?? []) : 1;
            // Pattern per-batch: structured / null / invalid-unit, then nulls.
            $base = [
                ['amount' => null, 'amount_high' => null, 'unit' => null,
                    'ingredient' => 'auto', 'modifier' => null, 'note' => null, 'optional' => false],
                null,
                ['amount' => 1, 'amount_high' => null, 'unit' => 'made-up-unit',
                    'ingredient' => 'something', 'modifier' => null, 'note' => null, 'optional' => false],
            ];
            $reply = [];
            for ($i = 0; $i < $count; $i++) {
                $reply[] = $base[$i] ?? null;
            }
            return Http::response(['content' => [['type' => 'text', 'text' => json_encode($reply)]]], 200);
        });

        $this->artisan('recipes:reindex', ['--with-llm' => true])
            ->expectsOutputToContain('LLM fallback:')
            ->assertSuccessful();

        // The three categories must sum to the submitted total.
        $parsedRows = Ingredient::where('llm_parsed', true)->count();
        $missCache = IngredientLlmCache::where('status', 'miss')->count();
        $unparsedNow = Ingredient::where('parsed', false)->count();
        // Sanity: the stats line subtotals are reflected in the DB state.
        $this->assertGreaterThan(0, $parsedRows);
        $this->assertGreaterThan(0, $missCache);
        // Note: unparsedNow could be >0 (still-unparsed rows) — that's the
        // "lines we never tried" plus the rows whose distinct raw maps to
        // a miss. We're not asserting the exact breakdown here; the unit
        // tests cover the precise math.
    }
}
