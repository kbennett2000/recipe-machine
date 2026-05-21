# TODO

Known issues and deferred work for recipe-machine. Items here are
out of scope for the current phase but worth fixing before v1
ships or in a Phase 11 polish pass.

## Deferrals (Phase 11 polish targets)

- [ ] Drop platform PHP 8.3 pin in composer.json (decided in Phase 0,
      revisit after the rest of the app stabilizes — see if it works
      on 8.4 with current dependencies).
- [ ] Synonym table for ingredient deduplication. Currently "butter"
      and "unsalted butter" don't merge in the shopping list. A
      curated synonym map would handle this without false-merge risk.
- [ ] Jump-to-step picker for cooking mode on 30+-step recipes
      (pie-crust has 45). Currently the user has to tap Next N times
      to resume mid-recipe.
- [ ] Compound-imprecise parser pattern: "salt, or to taste"
      doesn't parse cleanly. Documented workaround is the em-dash
      form. Real fix is parser-side recognition of "or <imprecise>"
      suffixes.
- [ ] Stepper input on cooking mode allows weird values (1e5,
      pasted decimals) — input mask is Phase 11 polish.
- [ ] Word-number timer detection: "chill for at least two hours"
      isn't caught because "two" isn't a digit. Out of scope unless
      a corpus pattern emerges that justifies it.
- [ ] Cooking-mode inline timer dedupe is by-label-only. If the same
      label appears in two steps of the same recipe, only one can
      run. Add stepIndex prefix to disambiguate if it becomes a real
      problem.
- [ ] Aisles::classify() uses non-thread-safe memoization. Fine for
      PHP-FPM, would matter under swoole/long-running.
- [ ] "Recently updated" on home page uses source_mtime which
      shuffles when hand-edits happen. Acceptable for v1 — revisit
      if a real "added/published" date workflow emerges.
- [ ] Empty Vanilla Ice Cream + Pasta Sauce method sections. Both
      lack method content in the source codex. Content-side fix
      when the user next cooks them.
- [ ] Cooking-mode swipe gesture for mobile step navigation. Currently
      Next/Previous buttons and ←/→/space keyboard nav cover the
      desktop case; a horizontal-swipe gesture would fit the
      hands-messy in-kitchen UX better on phones. Needs care around
      not conflicting with text selection or sidebar scroll.
- [ ] CookingStepFormatter (and MethodFormatter on the detail page)
      handle only **bold** + timer/temperature substitution. Inline
      markdown beyond that — italics, links, inline code, nested
      lists — renders as raw characters in step views. Becomes a
      real problem the moment a recipe uses italics in a method
      step. Audit MethodFormatter, and either extend both formatters
      or document the supported subset in docs/recipe-format.md.
- [ ] Distinguish LLM miss types in the cache. Currently
      `ingredient_llm_cache.status='miss'` collapses two cases:
      (a) the LLM returned null (legitimate non-ingredient
      rejection), and (b) the LLM returned a structured object
      that failed our schema/unit validation. Storing a short
      `miss_kind` enum ('null', 'invalid_schema', 'invalid_unit')
      would let future inspection know which case fired.
- [ ] Prompt tweak: discourage the LLM from putting "up to" in
      the note field when amount_high-without-amount is the
      pattern. The formatter renders "up to" as a prefix
      automatically; the redundant note is harmless but
      cosmetically noisy. Small prompt revision after collecting
      more real-world examples.
- [ ] Clean up redundant LLM cache entries that the rules-based
      parser can now handle. Specifically: the "Up to 1/4 cup toasted
      sesame seed oil" entry (from scallion-pancakes) is now
      rules-parseable thanks to Phase 11A.1. The cached hit is
      harmless but a small maintenance item — could be cleared via
      `recipes:llm-cache-clear --line='...'` if we want to keep the
      cache tidy.
- [ ] Future parser shape: "up to N to M unit X" (ranged upper-bound).
      Semantically muddy and absent from current corpus + LLM cache.
      Not blocking. Revisit if a real recipe surfaces this pattern.

## Resolved in v1.0 (Phase 10)

- ShoppingListTest CSRF/419 failures — `/shopping-list/calculate` is
  now exempted from CSRF validation (stateless JSON endpoint, no
  session mutation, no auth). All 329 tests pass.
- Self-hosted Fraunces + Inter fonts under [public/fonts/](public/fonts/).
- Named-volume migration shadowing — the docker-compose volume now
  binds only `database/database.sqlite`, so new migrations under
  `database/migrations/` are visible to the container immediately.
- Vite/Docker rebuild streamline — `Makefile` wraps the common
  workflows; the Dockerfile is now multi-stage and builds the Vite
  bundle into the image, so `docker compose up --build` works from
  a fresh clone with no host-side npm.
- Node installed in the Dockerfile — the PHP↔JS parity test runs
  for real, no silent skip.

## Content-side cleanups

- [ ] Convert shrimp-po-boys ingredient sub-group bullets to `###`
      headers. The recipe currently has `- For the Remoulade Sauce`,
      `- For the Fried Shrimp`, `- For the Po'Boy Assembly` as bullet
      items, which the LLM correctly tombstones as non-ingredients
      but which would render with proper sub-group styling if
      rewritten as `### Remoulade Sauce`, `### Fried Shrimp`,
      `### Po'Boy Assembly` headers. Mirrors the method-side cleanup
      done in Phase 2C. (navajo-tacos already uses this convention.)
- [ ] "baking soda bath" line in big-soft-pretzels resolves to
      baking soda (correctly) but loses the "bath" context, which
      matters for shopping-list aggregation. Either hand-edit the
      source line to split bath usage from topping usage, or wait
      for a future "ingredient role" feature.

## Won't-fix / out-of-scope decisions

- T/t single-letter unit forms (dropped in Phase 2A.1, documented in spec).
- Audio context unlock on session-restored timer completions
  (rare, silently degrades).
- AudioContext leak: _beep() creates a new AudioContext per timer
  completion and never calls .close(). Practically harmless — a
  single cooking session has 1-3 timers; the accumulation is bounded
  by the session lifetime and the browser releases on tab close.
  Adding .close() correctly would require waiting for the envelope
  to finish, which complicates the helper without practical benefit.
