# TODO

Known issues and deferred work for recipe-machine. Items here are
out of scope for the current phase but worth fixing before v1
ships or in a Phase 11 polish pass.

## Bugs

- [ ] ShoppingListTest CSRF/419 failures (5 tests). Pre-existing on
      main since Phase 6.x. Likely Laravel CSRF middleware needs
      to be excluded for the /shopping-list/calculate JSON endpoint,
      or the test needs to seed a CSRF token. Affects: tests only,
      not user-facing behavior.

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
- [ ] Self-host Fraunces + Inter font files in public/fonts/ instead
      of loading from Google Fonts CDN. Self-hosted ethos + privacy.
- [ ] Named-volume migration issue: Docker named volume captures all
      of database/ which hides newly-added migration files until
      `docker compose down -v`. Scope the volume to just the
      .sqlite file.
- [ ] Vite manifest / Docker rebuild gotcha: rebuilding JS/CSS on
      the host requires `docker compose build app` to update the
      container image. Worth a Makefile target or compose override
      to streamline.
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

## Won't-fix / out-of-scope decisions

- T/t single-letter unit forms (dropped in Phase 2A.1, documented in spec).
- Audio context unlock on session-restored timer completions
  (rare, silently degrades).
