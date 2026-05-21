# LLM ingredient-parser fallback

The rules-based ingredient parser handles the vast majority of recipe lines.
A small minority — section headers ("For the Sauce:"), instructions ("Braid
and egg-wash"), parentheticals ("(or kosher salt)"), and unconventional
phrasings — fall through with `parsed=false`. Phase 9 introduces an opt-in
LLM fallback that routes those lines through Claude Haiku and either
produces a structured parse or marks them as non-ingredients.

This document records the architectural decisions behind the fallback so
future contributors can extend it without re-litigating the same choices.

## Strict unit validation

When the LLM returns a parse, every unit it produces is checked against the
same `UnitMatcher` canonical list the rules-based parser uses (tsp, tbsp,
cup, floz, pint, quart, gallon, ml, l, g, kg, oz, lb, whole, pinch, dash,
splash, drizzle, handful, sprinkle, to-taste, as-needed). An LLM-produced
unit that doesn't match is **rejected outright** — the whole line is treated
as a parse failure and a miss tombstone is written.

Rationale: downstream code (shopping list aggregation, ingredient scaling,
the cooking-mode sidebar) assumes the unit column carries a known canonical
value. Letting through arbitrary strings would force every downstream
consumer to add a "what if the unit is weird?" branch. The cleanest
contract is to keep the rules-based parser as the authoritative type
gate; the LLM contributes structure, not new vocabulary.

If a recipe genuinely uses a unit not in the canonical list, the right fix
is to add the unit to `UnitMatcher` — not to relax LLM validation.

## SHA-1 cache keys

The `ingredient_llm_cache` table indexes by `raw_line_hash` (a sha1 of the
raw line) rather than by the raw text itself. The raw text is stored in
parallel for human readability and for the `recipes:llm-cache-clear --line='...'`
command.

Reasons:

- Ingredient lines can occasionally exceed 200 chars (parentheticals,
  multi-clause descriptions). SQLite's default page size doesn't index long
  TEXT columns efficiently.
- A fixed-length 40-char hash gives O(1) lookups with a small unique index
  regardless of the underlying string length.
- Collisions at this scale (hundreds, maybe thousands of distinct lines)
  are not a concern — sha1 collision probability is far below the floor
  where it would affect a cache lookup.

The trade-off is that direct DB inspection requires looking up via the
`raw_line` column (which IS stored) rather than scanning the index — but
the cache table is small enough that a `SELECT * FROM ingredient_llm_cache
WHERE raw_line LIKE '%...%'` is instant.

## Tombstone TTL = 30 days; transport errors don't tombstone

The cache has two row kinds:

- **Hit** (`status='hit'`, `parsed_payload` populated, `expires_at=NULL`):
  the LLM produced a usable parse. **Permanent.** Only cleared via
  `recipes:llm-cache-clear`.
- **Miss** (`status='miss'`, `parsed_payload=NULL`, `expires_at` set to
  `created_at + 30 days`): the LLM was called and either returned `null`
  (correctly identified as non-ingredient) or returned a structured object
  the parser couldn't validate. **Expires after 30 days** so the line can
  be re-attempted when a better model is available.

Crucially: **transport-level errors (5xx, timeout, malformed response)
do NOT write tombstones.** They return `null` to the caller for that
batch but leave the cache untouched, so a transient API failure doesn't
poison the cache. The next live run will retry every affected line from
scratch.

Why 30 days: short enough that a meaningful model improvement (every few
months) gets picked up automatically on the next reindex, long enough that
ordinary "I ran the indexer twice this week" usage doesn't burn API credits
re-asking about the same dead-end lines.

To force a re-attempt across all tombstones without touching hits, use
`php artisan recipes:llm-cache-clear --misses-only`.

## Indexer-only API calls

**The LLM is never called from the request path.** All API calls happen
during indexer commands:

- `php artisan recipes:reindex --with-llm`
- `php artisan recipes:llm-parse-fallback`

At request time (recipe detail page, search results, shopping list, cooking
mode) the cache is authoritative — a synchronous DB read at worst. The
`llm_parsed=true` flag on ingredient rows tells the detail page which lines
to mark with a ✨ indicator without doing any cache lookup at all.

Why this matters:

- The API has a latency budget that's incompatible with a page-render path
  (300-800ms vs. our usual <100ms server-render).
- A live-call path would need rate-limiting, retry budgets, fallback UI for
  the cold-cache case — all complexity we avoid by keeping the API call
  asynchronous-by-design.
- The fallback is a content-pipeline feature, not a feature of the running
  app. Treating it that way keeps the boundary clean.

If a future feature genuinely needs live LLM calls (e.g. a "rewrite this
recipe in a different style" button), that goes in a separate subsystem
with its own architecture — not in the ingredient fallback pipeline.

## Related files

- `app/Recipes/LLM/IngredientLLMParser.php` — the parser service
- `app/Models/IngredientLlmCache.php` — cache model
- `app/Console/Commands/LLMParseFallbackRecipes.php` — standalone command
- `app/Console/Commands/LLMCacheClear.php` — cache invalidation command
- `app/Console/Commands/ReindexRecipes.php` — `--with-llm` flag wiring
- `config/recipe-machine.php` — feature flag, model, batch size, TTL
- `tests/Unit/IngredientLLMParserTest.php` — cache + prompt/parsing tests
- `tests/Feature/LLMFallbackIntegrationTest.php` — end-to-end with mocked HTTP
