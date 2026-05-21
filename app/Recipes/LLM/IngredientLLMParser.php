<?php

declare(strict_types=1);

namespace App\Recipes\LLM;

use App\Models\Ingredient;
use App\Models\IngredientLlmCache;
use App\Recipes\Parser\ParsedIngredient;
use App\Recipes\Parser\UnitMatcher;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 9 — LLM-backed fallback parser for ingredient lines that the
 * rules-based parser couldn't structure.
 *
 * Public API: parseBatch(array<string>): array<string, ?ParsedIngredient>
 *
 * Returns a map of raw_line => ParsedIngredient (on hit) or null (on miss).
 * Cache is consulted FIRST per line; only uncached and expired-miss lines
 * are sent to the API. The API call is batched: up to batch_size lines in
 * one round-trip. The model returns a JSON array; we parse + validate each
 * element and persist results to the cache.
 *
 * Graceful degradation:
 *   - No API key → every line returns null, no cache writes.
 *   - Disabled in config → every line returns null, no cache writes.
 *   - Network error / 5xx → null for the failing batch, no cache writes
 *     (so a later run will retry).
 *   - Malformed response → null for the failing batch.
 *   - Per-line validation failure (e.g. invalid unit) → that line becomes
 *     a fresh tombstone (cache writes 'miss' so we don't burn credits
 *     re-asking immediately).
 */
final class IngredientLLMParser
{
    public function __construct(
        private readonly UnitMatcher $unitMatcher = new UnitMatcher,
    ) {}

    /**
     * @param  list<string>  $rawLines
     * @return array<string, ?ParsedIngredient>
     */
    public function parseBatch(array $rawLines): array
    {
        $rawLines = array_values(array_unique(array_filter($rawLines, fn ($l) => is_string($l) && trim($l) !== '')));
        if ($rawLines === []) {
            return [];
        }

        $result = [];
        $needsApi = [];

        foreach ($rawLines as $line) {
            $cache = IngredientLlmCache::where('raw_line_hash', IngredientLlmCache::hashFor($line))->first();
            if ($cache !== null) {
                if ($cache->isHit()) {
                    $parsed = $this->reviveFromPayload($line, $cache->parsed_payload ?? []);
                    $result[$line] = $parsed;
                    continue;
                }
                if ($cache->isLiveMiss()) {
                    // Tombstone still in TTL — skip the API.
                    $result[$line] = null;
                    continue;
                }
                // Expired tombstone — fall through to re-attempt.
            }
            $needsApi[] = $line;
        }

        if (! $this->isEnabled()) {
            foreach ($needsApi as $line) {
                $result[$line] = null;
            }
            return $result;
        }

        $batchSize = max(1, (int) (config('recipe-machine.llm.batch_size') ?? 20));
        foreach (array_chunk($needsApi, $batchSize) as $chunk) {
            $apiResults = $this->callApi($chunk);
            if ($apiResults === null) {
                // Batch-level failure (network/5xx). Don't cache; mark all
                // null and let the next run retry.
                foreach ($chunk as $line) {
                    $result[$line] = null;
                }
                continue;
            }
            foreach ($chunk as $i => $line) {
                $payload = $apiResults[$i] ?? null;
                $parsed = $payload === null ? null : $this->reviveFromPayload($line, $payload);
                $this->writeCache($line, $parsed, $payload);
                $result[$line] = $parsed;
            }
        }

        return $result;
    }

    public function isEnabled(): bool
    {
        return (bool) (config('recipe-machine.llm.enabled') ?? false)
            && ! empty(config('recipe-machine.llm.api_key'));
    }

    /**
     * Walk over already-loaded unparsed ingredient rows, batch-parse them
     * through the LLM, and persist successful parses back to the DB with
     * llm_parsed=true.
     *
     * Stats are disjoint — parsed + cached_misses + still_unparsed = submitted:
     *   submitted        - distinct raw lines we considered
     *   parsed           - successfully structured (cache hit OR new API hit)
     *   cached_misses    - LLM tried and returned null/invalid; tombstoned
     *                      (either an existing live tombstone or a fresh
     *                      one written by this run)
     *   still_unparsed   - never got submitted: no API key, transport
     *                      failure, batch error. These have no cache row.
     *   api_called       - true if any API request actually fired
     *
     * @param  Collection<int,Ingredient>  $rows
     * @return array{submitted:int, parsed:int, cached_misses:int, still_unparsed:int, api_called:bool}
     */
    public function applyToUnparsedRows(Collection $rows): array
    {
        $rows = $rows->filter(fn (Ingredient $i) => ! $i->parsed);
        if ($rows->isEmpty()) {
            return ['submitted' => 0, 'parsed' => 0, 'cached_misses' => 0, 'still_unparsed' => 0, 'api_called' => false];
        }
        $rawLines = $rows->pluck('raw')->unique()->values()->all();

        $cacheCountBefore = IngredientLlmCache::count();
        $parseMap = $this->parseBatch($rawLines);
        $apiCalled = IngredientLlmCache::count() > $cacheCountBefore;

        // After parseBatch, look up cache rows so we can tell "tried and got
        // a miss" apart from "never tried" (transport failure or LLM off).
        $hashes = array_map(IngredientLlmCache::hashFor(...), $rawLines);
        $cacheRows = IngredientLlmCache::whereIn('raw_line_hash', $hashes)->get()->keyBy('raw_line_hash');

        $parsedCount = 0;
        $missCount = 0;
        $stillUnparsedCount = 0;

        foreach ($rows as $row) {
            $parsed = $parseMap[$row->raw] ?? null;
            if ($parsed !== null) {
                $row->parsed = true;
                $row->llm_parsed = true;
                $row->amount = $parsed->amount === null ? null : (float) $parsed->amount;
                $row->amount_high = $parsed->amountHigh;
                $row->unit = $parsed->unit;
                $row->unit_class = $this->unitClassFor($parsed->unit);
                $row->ingredient = $parsed->ingredient;
                $row->modifier = $parsed->modifier;
                $row->note = $parsed->note;
                $row->optional = $parsed->optional;
                $row->save();
                $parsedCount++;
                continue;
            }
            $cache = $cacheRows[IngredientLlmCache::hashFor($row->raw)] ?? null;
            if ($cache !== null && $cache->status === 'miss') {
                $missCount++;
            } else {
                $stillUnparsedCount++;
            }
        }
        return [
            'submitted' => count($rawLines),
            'parsed' => $parsedCount,
            'cached_misses' => $missCount,
            'still_unparsed' => $stillUnparsedCount,
            'api_called' => $apiCalled,
        ];
    }

    /**
     * Classify the supplied raw lines against the cache WITHOUT calling the
     * API or mutating anything. Drives the --dry-run preview on both
     * `recipes:llm-parse-fallback` and `recipes:reindex --with-llm`.
     *
     * Returns disjoint counts plus a list of the first $sampleSize
     * "would_submit" lines for the report.
     *
     * @param  list<string>  $rawLines
     * @return array{
     *     total: int,
     *     cached_hits: int,
     *     cached_misses: int,
     *     would_submit: int,
     *     sample_to_submit: list<string>
     * }
     */
    public function previewBatch(array $rawLines, int $sampleSize = 5): array
    {
        $rawLines = array_values(array_unique(array_filter($rawLines, fn ($l) => is_string($l) && trim($l) !== '')));
        $total = count($rawLines);
        if ($total === 0) {
            return ['total' => 0, 'cached_hits' => 0, 'cached_misses' => 0, 'would_submit' => 0, 'sample_to_submit' => []];
        }
        $hashes = array_map(IngredientLlmCache::hashFor(...), $rawLines);
        $cacheRows = IngredientLlmCache::whereIn('raw_line_hash', $hashes)->get()->keyBy('raw_line_hash');

        $hits = 0;
        $misses = 0;
        $wouldSubmit = [];
        foreach ($rawLines as $line) {
            $cache = $cacheRows[IngredientLlmCache::hashFor($line)] ?? null;
            if ($cache === null) {
                $wouldSubmit[] = $line;
                continue;
            }
            if ($cache->isHit()) {
                $hits++;
                continue;
            }
            if ($cache->isLiveMiss()) {
                $misses++;
                continue;
            }
            // Expired tombstone — would be re-attempted next live run.
            $wouldSubmit[] = $line;
        }
        return [
            'total' => $total,
            'cached_hits' => $hits,
            'cached_misses' => $misses,
            'would_submit' => count($wouldSubmit),
            'sample_to_submit' => array_slice($wouldSubmit, 0, max(0, $sampleSize)),
        ];
    }

    /**
     * Build the user message for a chunk of raw lines.
     *
     * @param  list<string>  $lines
     */
    public function buildUserMessage(array $lines): string
    {
        $payload = ['lines' => array_values($lines)];
        return "Parse the following array of ingredient lines into structured objects. "
            ."Return ONLY a JSON array of the same length, in the same order. Each element "
            ."must be either an object matching the schema in the system prompt OR null if "
            ."the line isn't a real ingredient (section header, instruction, metadata, blank).\n\n"
            ."Input:\n".json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    public function systemPrompt(): string
    {
        // Keep this readable inline — the prompt IS the spec.
        return <<<'PROMPT'
You are a structured-data extractor for cooking recipe ingredients. The rules-based parser has already failed on the lines you'll receive. Your job is to either (a) produce a structured ingredient object, or (b) decide the line is not an ingredient and return null.

You will receive a JSON object with a "lines" array of N raw strings. Respond with ONLY a JSON array of length N (no prose, no code fences), where each element is either:

  - null, if the line is a section header, instruction, metadata, or otherwise not an ingredient
  - an object with these EXACT keys (no extras):

      {
        "amount":       float | null,   // null if no numeric quantity
        "amount_high":  float | null,   // upper bound for ranges; null otherwise
        "unit":         string | null,  // MUST be one of the canonical units below, or null
        "ingredient":   string,         // the ingredient name; required, lowercased, no leading article
        "modifier":     string | null,  // preparation state after a comma ("diced", "softened")
        "note":         string | null,  // free-form note (post-em-dash or parenthetical detail)
        "optional":     bool            // whether the line is marked optional
      }

Canonical units (use EXACTLY these strings; map synonyms):
  Volume:    tsp, tbsp, cup, floz, pint, quart, gallon, ml, l
  Weight:    g, kg, oz, lb
  Count:     whole          (use for clove, slice, sprig, head, bunch, can, jar, stick, etc.)
  Imprecise: pinch, dash, splash, drizzle, handful, sprinkle, to-taste, as-needed

If the line has no quantitative unit but is still an ingredient (e.g. "Olive oil", "Kosher salt"), set unit to null and put the bare ingredient name in `ingredient`.

Decision rules:
  - Section headers like "For the Sauce:", "For egg wash:" → null
  - Instructions like "Braid and egg-wash", "Long room-temperature rise" → null
  - Recipe metadata like "Yields 6 servings", "Original recipe (1X) yields 8 servings" → null
  - Multi-clause garnish lists where you can't pick a primary ingredient ("chives, extra shredded cheddar cheese and bacon") → null
  - Imprecise but real ingredients ("whipped cream", "Olive oil") → object with unit=null

Examples:

Input line: "Melted butter for brushing after baking"
Output:     {"amount": null, "amount_high": null, "unit": null, "ingredient": "melted butter", "modifier": null, "note": "for brushing after baking", "optional": false}

Input line: "Coarse pretzel salt (or kosher salt)"
Output:     {"amount": null, "amount_high": null, "unit": null, "ingredient": "coarse pretzel salt", "modifier": null, "note": "or kosher salt", "optional": false}

Input line: "Shredded iceberg lettuce"
Output:     {"amount": null, "amount_high": null, "unit": null, "ingredient": "iceberg lettuce", "modifier": "shredded", "note": null, "optional": false}

Input line: "For the Remoulade Sauce"
Output:     null

Input line: "Up to 1/4 cup toasted sesame seed oil"
Output:     {"amount": null, "amount_high": 0.25, "unit": "cup", "ingredient": "toasted sesame seed oil", "modifier": null, "note": "up to", "optional": false}
PROMPT;
    }

    /**
     * Make one API call with a batch of lines. Returns an array of length
     * equal to $lines, or null on transport/parse failure.
     *
     * @param  list<string>  $lines
     * @return array<int, array<string, mixed>|null>|null
     */
    private function callApi(array $lines): ?array
    {
        $apiKey = config('recipe-machine.llm.api_key');
        $model = config('recipe-machine.llm.model');
        $baseUrl = rtrim((string) config('recipe-machine.llm.api_base_url'), '/');
        $timeout = (int) (config('recipe-machine.llm.timeout_seconds') ?? 30);

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout($timeout)->post($baseUrl.'/v1/messages', [
                'model' => $model,
                'max_tokens' => 2048,
                'system' => $this->systemPrompt(),
                'messages' => [[
                    'role' => 'user',
                    'content' => $this->buildUserMessage($lines),
                ]],
            ]);
        } catch (ConnectionException $e) {
            Log::warning('IngredientLLMParser: connection error', ['error' => $e->getMessage()]);
            return null;
        } catch (Throwable $e) {
            Log::warning('IngredientLLMParser: unexpected error', ['error' => $e->getMessage()]);
            return null;
        }

        if (! $response->successful()) {
            Log::warning('IngredientLLMParser: non-2xx response', [
                'status' => $response->status(),
                'body' => substr((string) $response->body(), 0, 500),
            ]);
            return null;
        }

        $body = $response->json();
        $text = $body['content'][0]['text'] ?? null;
        if (! is_string($text)) {
            Log::warning('IngredientLLMParser: response missing content[0].text', ['body' => $body]);
            return null;
        }

        $array = $this->extractJsonArray($text);
        if ($array === null) {
            Log::warning('IngredientLLMParser: could not extract JSON array from response', ['text' => substr($text, 0, 500)]);
            return null;
        }
        if (count($array) !== count($lines)) {
            Log::warning('IngredientLLMParser: response length mismatch', [
                'expected' => count($lines),
                'got' => count($array),
            ]);
            return null;
        }
        return $array;
    }

    /**
     * Extract a JSON array from a (possibly noisy) text response.
     * Handles plain JSON, ```json ... ``` fences, and the most common
     * "here's the JSON" prose-then-array shape by falling back to the
     * first '[' through the last ']'.
     */
    public function extractJsonArray(string $text): ?array
    {
        $trimmed = trim($text);

        // Try direct decode.
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Try code-fence extraction.
        if (preg_match('/```(?:json)?\s*([\[\{].*?[\]\}])\s*```/s', $trimmed, $m)) {
            $decoded = json_decode($m[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        // Last-resort: first '[' to matching last ']'.
        $first = strpos($trimmed, '[');
        $last = strrpos($trimmed, ']');
        if ($first !== false && $last !== false && $last > $first) {
            $slice = substr($trimmed, $first, $last - $first + 1);
            $decoded = json_decode($slice, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    /**
     * Validate one LLM-returned object and turn it into a ParsedIngredient.
     * Returns null if the payload is missing required keys, has wrong types,
     * or carries an invalid unit.
     */
    private function reviveFromPayload(string $rawLine, ?array $payload): ?ParsedIngredient
    {
        if ($payload === null) {
            return null;
        }
        // Required keys.
        $required = ['amount', 'amount_high', 'unit', 'ingredient', 'modifier', 'note', 'optional'];
        foreach ($required as $key) {
            if (! array_key_exists($key, $payload)) {
                return null;
            }
        }
        // Ingredient must be a non-empty string (we don't manufacture rows
        // without something to call them).
        $ingredient = $payload['ingredient'];
        if (! is_string($ingredient) || trim($ingredient) === '') {
            return null;
        }
        // Validate unit against the canonical list (via UnitMatcher).
        $unit = $payload['unit'];
        $unitClass = null;
        if ($unit !== null) {
            if (! is_string($unit)) {
                return null;
            }
            $matched = $this->unitMatcher->match($unit);
            if ($matched === null) {
                return null;
            }
            $unit = $matched->canonical;
            $unitClass = $matched->class->value;
            // (We don't expose unit_class through ParsedIngredient itself —
            // the indexer derives it the same way for rules-parsed rows.)
        }
        $amount = $this->coerceNumberOrNull($payload['amount']);
        $amountHigh = $this->coerceNumberOrNull($payload['amount_high']);
        $modifier = $this->coerceStringOrNull($payload['modifier']);
        $note = $this->coerceStringOrNull($payload['note']);
        $optional = (bool) $payload['optional'];

        return new ParsedIngredient(
            raw: $rawLine,
            parsed: true,
            amount: $amount,
            amountHigh: $amountHigh === null ? null : (float) $amountHigh,
            unit: $unit,
            ingredient: trim($ingredient),
            modifier: $modifier,
            note: $note,
            optional: $optional,
            group: null,
        );
    }

    /**
     * Resolve the unit class for a canonical unit string. Used by the
     * indexer when persisting an LLM-derived ingredient row.
     */
    public function unitClassFor(?string $canonicalUnit): ?string
    {
        if ($canonicalUnit === null) {
            return null;
        }
        $matched = $this->unitMatcher->match($canonicalUnit);
        return $matched?->class->value;
    }

    private function coerceNumberOrNull(mixed $v): float|string|null
    {
        if ($v === null) {
            return null;
        }
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_string($v) && is_numeric($v)) {
            return (float) $v;
        }
        return null;
    }

    private function coerceStringOrNull(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if (! is_string($v)) {
            return null;
        }
        $trimmed = trim($v);
        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * Write the cache for one line.
     * - Successful parse → 'hit' with parsed_payload (permanent).
     * - Failed validation or null → 'miss' with TTL expiry.
     */
    private function writeCache(string $line, ?ParsedIngredient $parsed, ?array $payload): void
    {
        $hash = IngredientLlmCache::hashFor($line);
        $model = (string) config('recipe-machine.llm.model');
        $ttlDays = (int) (config('recipe-machine.llm.cache_tombstone_ttl_days') ?? 30);

        if ($parsed !== null) {
            IngredientLlmCache::updateOrCreate(
                ['raw_line_hash' => $hash],
                [
                    'raw_line' => $line,
                    'status' => 'hit',
                    'parsed_payload' => $payload,
                    'model_used' => $model,
                    'expires_at' => null,
                ],
            );
            return;
        }
        IngredientLlmCache::updateOrCreate(
            ['raw_line_hash' => $hash],
            [
                'raw_line' => $line,
                'status' => 'miss',
                'parsed_payload' => null,
                'model_used' => $model,
                'expires_at' => CarbonImmutable::now()->addDays($ttlDays),
            ],
        );
    }
}
