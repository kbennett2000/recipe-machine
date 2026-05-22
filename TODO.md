# TODO

Known issues and deferred work for recipe-machine. Items here are
out of scope for the current phase but worth fixing before v1
ships or in a Phase 11 polish pass.

## Completed in v1.1 (Phase 11)

The editor closed the "markdown-only edit workflow" gap from v1.0. A
stranger landing on the repo can now create, edit, and delete recipes
in the web UI without touching a terminal.

- **Web editor** — `/recipes/<slug>/edit` with a form mode (live
  preview, sortable rows, sub-groups, mode-toggle round-trip) and a
  raw markdown mode with syntax cues. Both modes hit the same
  server-side parse/serialize/preview endpoints (no JS twin of the
  parser).
- **Create flow** — `/recipes/new` with category required, title
  required, and a live slug-derivation preview ("My Sandwich Bread →
  `my-sandwich-bread`"). Slug becomes immutable after first save.
- **Delete flow** — small rose-tinted "Delete recipe" link in the
  editor footer with a confirmation dialog. Removes the file from
  disk, removes the recipe from the index, redirects to the category
  listing. Inbound cross-references go unresolved (history preserved).
- **Convert-to-structured** — unparsed lines in the "Verify these"
  section now POST to `/edit/parse-line` which tries rules → LLM
  cache → fallback. Successful conversions get a ✨ "LLM cache" or
  ⚠ "best-effort" badge so the user knows what to review before
  saving.
- **Concurrency awareness** — every 10s the editor polls
  `/edit/mtime` and shows an amber banner if the file changed on disk
  out-of-band ("$EDITOR in another window", `git pull`, etc).
- **The previous "edit recipes in $EDITOR only" constraint from v1.0
  is no longer the only path.** Both workflows still work, the
  markdown file on disk is still the source of truth — the web
  editor is just another writer that goes through the same
  `RecipeFileWriter` → atomic-write → `RecipeReindexer` pipeline.
- **Workflow follow-on** — `recipes/desserts/vanilla-ice-cream.md`
  and `recipes/sauces/pasta-sauce.md` (the empty-method recipes from
  v1.0) and the shrimp-po-boys sub-group cleanup are now 30-second
  fixes via the editor's verify-these section instead of terminal
  work. The content-side TODO entries below stay open (the recipes
  haven't been fixed yet), but the path-to-fix has moved.

A list of follow-on issues caught during the editor build:

- [ ] "+ New category" inline affordance. Adding a recipe to a
      category that doesn't have a directory yet currently requires
      `mkdir recipes/<new-cat>/` in a terminal first. The category
      dropdown only lists existing on-disk directories. A small
      "+ new category" affordance in the dropdown that creates the
      directory on submit would close this gap.
- [ ] Phone-specific data-entry UX. The current mobile editor is
      reference-editing quality (good for tweaking an existing
      recipe), not first-draft quality. Long-press to drag works but
      isn't discoverable; the soft keyboard covers most of the
      ingredient table on portrait phones. A "tap-to-promote, then
      reorder" UX would fit hands-busy mobile better.
- [ ] Modal positioning on portrait phones with the soft keyboard up.
      The delete confirmation dialog centers in the viewport, which
      can sit awkwardly when the keyboard reduces visible height.
      Not blocking — the user typically isn't typing when they hit
      Delete — but worth a `padding-bottom: env(keyboard-inset-height)`
      tweak when iOS Safari ships the property more widely.
- [ ] Reindex as a queued job for larger corpora. Save → `reindexOne`
      is synchronous (~50ms for one recipe today). At a few hundred
      recipes this stays fine; at a few thousand the user would
      notice the save round-trip. Move to a queue + flash-and-redirect
      pattern when that becomes real.
- [ ] Visual feedback for non-parsable amounts. The form's amount
      field now accepts ¾, 1 ½, 1 1/2, etc. (Phase 11H.4), but
      garbage like "abc" silently becomes null. A small inline hint
      ("not a number") under the amount field on blur would help
      a user spot a typo before they save.
- [ ] Empty Vanilla Ice Cream + Pasta Sauce method sections (still
      need content). Was a v1.0 deferral; now editable via the web
      UI instead of terminal. See content-side cleanups below.

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
      As of v1.1 the editor's "Add group" flow + verify-these
      cleanup makes this a UI fix instead of a terminal edit.
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
