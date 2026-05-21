<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\IngredientLlmCache;
use App\Recipes\LLM\IngredientLLMParser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 9 — IngredientLLMParser cache + response-handling tests.
 *
 * All API traffic is faked via Http::fake(); no real Anthropic calls are
 * made from the test suite. Real-corpus verification is a manual user
 * step (the user has the API key + credits; the assistant doesn't).
 */
final class IngredientLLMParserTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Enable the LLM path so callApi runs; tests set Http::fake() for traffic.
        config([
            'recipe-machine.llm.enabled' => true,
            'recipe-machine.llm.api_key' => 'test-key',
            'recipe-machine.llm.model' => 'claude-haiku-4-5-20251001',
            'recipe-machine.llm.batch_size' => 5,
            'recipe-machine.llm.cache_tombstone_ttl_days' => 30,
            'recipe-machine.llm.api_base_url' => 'https://api.anthropic.com',
        ]);
    }

    public function test_cache_hit_returns_parsed_without_api_call(): void
    {
        // Seed the cache with a hit.
        $line = 'Melted butter for brushing';
        IngredientLlmCache::create([
            'raw_line' => $line,
            'raw_line_hash' => IngredientLlmCache::hashFor($line),
            'status' => 'hit',
            'parsed_payload' => [
                'amount' => null, 'amount_high' => null, 'unit' => null,
                'ingredient' => 'melted butter', 'modifier' => null,
                'note' => 'for brushing', 'optional' => false,
            ],
            'model_used' => 'claude-haiku-4-5-20251001',
        ]);

        Http::fake(); // would fail the test if any call escapes.

        $parser = new IngredientLLMParser;
        $result = $parser->parseBatch([$line]);

        $this->assertNotNull($result[$line]);
        $this->assertSame('melted butter', $result[$line]->ingredient);
        $this->assertSame('for brushing', $result[$line]->note);
        Http::assertNothingSent();
    }

    public function test_cache_live_miss_returns_null_without_api_call(): void
    {
        $line = 'For the Sauce:';
        IngredientLlmCache::create([
            'raw_line' => $line,
            'raw_line_hash' => IngredientLlmCache::hashFor($line),
            'status' => 'miss',
            'parsed_payload' => null,
            'model_used' => 'claude-haiku-4-5-20251001',
            'expires_at' => CarbonImmutable::now()->addDays(15),
        ]);

        Http::fake();

        $parser = new IngredientLLMParser;
        $result = $parser->parseBatch([$line]);

        $this->assertNull($result[$line]);
        Http::assertNothingSent();
    }

    public function test_expired_tombstone_triggers_re_attempt(): void
    {
        $line = 'For the Sauce:';
        IngredientLlmCache::create([
            'raw_line' => $line,
            'raw_line_hash' => IngredientLlmCache::hashFor($line),
            'status' => 'miss',
            'parsed_payload' => null,
            'model_used' => 'claude-haiku-4-5-20251001',
            'expires_at' => CarbonImmutable::now()->subDays(1),
        ]);

        Http::fake([
            '*' => Http::response($this->fakeApiBody([null]), 200),
        ]);

        $parser = new IngredientLLMParser;
        $result = $parser->parseBatch([$line]);

        $this->assertNull($result[$line]);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/messages'));
    }

    public function test_well_formed_response_yields_parsed_ingredient(): void
    {
        Http::fake([
            '*' => Http::response($this->fakeApiBody([[
                'amount' => null, 'amount_high' => null, 'unit' => null,
                'ingredient' => 'olive oil', 'modifier' => null,
                'note' => null, 'optional' => false,
            ]]), 200),
        ]);

        $parser = new IngredientLLMParser;
        $result = $parser->parseBatch(['Olive oil']);

        $this->assertNotNull($result['Olive oil']);
        $this->assertSame('olive oil', $result['Olive oil']->ingredient);
        $this->assertTrue($result['Olive oil']->parsed);

        // Hit cached for next time.
        $cache = IngredientLlmCache::where('raw_line_hash', IngredientLlmCache::hashFor('Olive oil'))->first();
        $this->assertNotNull($cache);
        $this->assertSame('hit', $cache->status);
        $this->assertNull($cache->expires_at);
    }

    public function test_response_with_prose_around_json_still_extracts(): void
    {
        $payload = [[
            'amount' => null, 'amount_high' => null, 'unit' => null,
            'ingredient' => 'shredded iceberg lettuce', 'modifier' => 'shredded',
            'note' => null, 'optional' => false,
        ]];
        $textBody = "Sure! Here's the parsed result:\n\n```json\n".json_encode($payload)."\n```\n\nLet me know if you need anything else.";

        Http::fake([
            '*' => Http::response(['content' => [['text' => $textBody, 'type' => 'text']]], 200),
        ]);

        $parser = new IngredientLLMParser;
        $result = $parser->parseBatch(['Shredded iceberg lettuce']);

        $this->assertNotNull($result['Shredded iceberg lettuce']);
        $this->assertSame('shredded iceberg lettuce', $result['Shredded iceberg lettuce']->ingredient);
    }

    public function test_response_with_invalid_unit_returns_null_and_writes_miss(): void
    {
        Http::fake([
            '*' => Http::response($this->fakeApiBody([[
                'amount' => 2, 'amount_high' => null, 'unit' => 'scoop',
                'ingredient' => 'ice cream', 'modifier' => null,
                'note' => null, 'optional' => false,
            ]]), 200),
        ]);

        $parser = new IngredientLLMParser;
        // 'scoop' isn't in our canonical unit list (UnitMatcher knows
        // tsp/tbsp/cup/g/kg/oz/lb/whole/pinch/dash/splash/drizzle/handful/
        // sprinkle/to-taste/as-needed and the count-class synonyms — scoop
        // isn't among them). The parser should reject and cache a miss.
        $result = $parser->parseBatch(['2 scoops ice cream']);

        $this->assertNull($result['2 scoops ice cream']);

        $cache = IngredientLlmCache::where('raw_line_hash', IngredientLlmCache::hashFor('2 scoops ice cream'))->first();
        $this->assertNotNull($cache);
        $this->assertSame('miss', $cache->status);
        $this->assertNotNull($cache->expires_at);
    }

    public function test_response_missing_required_key_returns_null(): void
    {
        Http::fake([
            '*' => Http::response($this->fakeApiBody([[
                // Missing the required "ingredient" key.
                'amount' => null, 'amount_high' => null, 'unit' => null,
                'modifier' => null, 'note' => null, 'optional' => false,
            ]]), 200),
        ]);

        $parser = new IngredientLLMParser;
        $result = $parser->parseBatch(['Something']);

        $this->assertNull($result['Something']);
    }

    public function test_explicit_null_in_response_yields_miss_cache(): void
    {
        Http::fake([
            '*' => Http::response($this->fakeApiBody([null]), 200),
        ]);

        $parser = new IngredientLLMParser;
        $result = $parser->parseBatch(['For the Sauce:']);

        $this->assertNull($result['For the Sauce:']);

        $cache = IngredientLlmCache::where('raw_line_hash', IngredientLlmCache::hashFor('For the Sauce:'))->first();
        $this->assertNotNull($cache);
        $this->assertSame('miss', $cache->status);
    }

    public function test_5xx_response_returns_null_without_caching(): void
    {
        Http::fake([
            '*' => Http::response('upstream error', 503),
        ]);

        $parser = new IngredientLLMParser;
        $result = $parser->parseBatch(['Olive oil']);

        $this->assertNull($result['Olive oil']);
        // Critically: no cache row written so the next run retries.
        $this->assertSame(0, IngredientLlmCache::count());
    }

    public function test_response_length_mismatch_returns_null_without_caching(): void
    {
        Http::fake([
            '*' => Http::response($this->fakeApiBody([
                [
                    'amount' => null, 'amount_high' => null, 'unit' => null,
                    'ingredient' => 'salt', 'modifier' => null,
                    'note' => null, 'optional' => false,
                ],
            ]), 200),
        ]);

        $parser = new IngredientLLMParser;
        // Asked for 2 lines, model returned 1 — abort, don't cache anything.
        $result = $parser->parseBatch(['salt', 'pepper']);

        $this->assertNull($result['salt']);
        $this->assertNull($result['pepper']);
        $this->assertSame(0, IngredientLlmCache::count());
    }

    public function test_disabled_via_config_returns_null_without_api_call(): void
    {
        config(['recipe-machine.llm.enabled' => false]);
        Http::fake();

        $parser = new IngredientLLMParser;
        $result = $parser->parseBatch(['Olive oil']);

        $this->assertNull($result['Olive oil']);
        Http::assertNothingSent();
    }

    public function test_missing_api_key_returns_null_without_api_call(): void
    {
        config(['recipe-machine.llm.api_key' => null]);
        Http::fake();

        $parser = new IngredientLLMParser;
        $result = $parser->parseBatch(['Olive oil']);

        $this->assertNull($result['Olive oil']);
        Http::assertNothingSent();
    }

    public function test_extract_json_array_handles_raw_array(): void
    {
        $parser = new IngredientLLMParser;
        $this->assertSame([1, 2, 3], $parser->extractJsonArray('[1,2,3]'));
    }

    public function test_extract_json_array_handles_code_fence(): void
    {
        $parser = new IngredientLLMParser;
        $text = "Sure!\n```json\n[1,2,null]\n```";
        $this->assertSame([1, 2, null], $parser->extractJsonArray($text));
    }

    public function test_extract_json_array_handles_prose_then_array(): void
    {
        $parser = new IngredientLLMParser;
        $text = "Here you go: [{\"x\":1},null]";
        $result = $parser->extractJsonArray($text);
        $this->assertCount(2, $result);
    }

    public function test_extract_json_array_returns_null_on_garbage(): void
    {
        $parser = new IngredientLLMParser;
        $this->assertNull($parser->extractJsonArray('not json at all'));
    }

    public function test_preview_batch_classifies_lines_against_cache(): void
    {
        // Seed one hit, one live miss; pass three lines through preview to
        // confirm the third is reported as "would submit" and the cached
        // pair are recognized without any HTTP traffic.
        IngredientLlmCache::create([
            'raw_line' => 'cached hit line',
            'raw_line_hash' => IngredientLlmCache::hashFor('cached hit line'),
            'status' => 'hit',
            'parsed_payload' => [
                'amount' => null, 'amount_high' => null, 'unit' => null,
                'ingredient' => 'whatever', 'modifier' => null,
                'note' => null, 'optional' => false,
            ],
            'model_used' => 'claude-haiku-4-5-20251001',
        ]);
        IngredientLlmCache::create([
            'raw_line' => 'cached miss line',
            'raw_line_hash' => IngredientLlmCache::hashFor('cached miss line'),
            'status' => 'miss',
            'parsed_payload' => null,
            'model_used' => 'claude-haiku-4-5-20251001',
            'expires_at' => CarbonImmutable::now()->addDays(15),
        ]);

        Http::fake(); // would fail if anything got sent

        $parser = new IngredientLLMParser;
        $preview = $parser->previewBatch([
            'cached hit line',
            'cached miss line',
            'uncached new line',
        ]);

        $this->assertSame(3, $preview['total']);
        $this->assertSame(1, $preview['cached_hits']);
        $this->assertSame(1, $preview['cached_misses']);
        $this->assertSame(1, $preview['would_submit']);
        $this->assertSame(['uncached new line'], $preview['sample_to_submit']);
        Http::assertNothingSent();
    }

    public function test_preview_batch_treats_expired_tombstone_as_would_submit(): void
    {
        IngredientLlmCache::create([
            'raw_line' => 'expired line',
            'raw_line_hash' => IngredientLlmCache::hashFor('expired line'),
            'status' => 'miss',
            'parsed_payload' => null,
            'model_used' => 'claude-haiku-4-5-20251001',
            'expires_at' => CarbonImmutable::now()->subDays(1),
        ]);

        Http::fake();

        $parser = new IngredientLLMParser;
        $preview = $parser->previewBatch(['expired line']);

        $this->assertSame(0, $preview['cached_hits']);
        $this->assertSame(0, $preview['cached_misses']);
        $this->assertSame(1, $preview['would_submit']);
        Http::assertNothingSent();
    }

    /**
     * Helper: build an Anthropic-shaped response body wrapping a JSON array.
     *
     * @param  array<int, array<string,mixed>|null>  $payload
     * @return array<string, mixed>
     */
    private function fakeApiBody(array $payload): array
    {
        return [
            'id' => 'msg_test',
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'text', 'text' => json_encode($payload)],
            ],
            'model' => 'claude-haiku-4-5-20251001',
            'stop_reason' => 'end_turn',
        ];
    }
}
