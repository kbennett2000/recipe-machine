# Recipe Machine

> A self-hosted recipe library that turns a directory of markdown
> files into a searchable, scalable, shopping-list-generating,
> editable web app. Designed for the home LAN.

![Home page](docs/screenshots/home.png)

## What this is

Recipe Machine started as a personal pain point. A few dozen
recipes lived as markdown in a private repo — perfect for editing
in `$EDITOR` and version-controlling, but unfriendly for any other
use: no scaling for guests, no shopping list when meal-planning,
no full-text search, no "what was that bread I made last fall."
Browsers want HTML, not folders of `.md` files.

So this is the smallest possible thing that fixes that: a
single-container web app that **treats the markdown files as the
source of truth** and the SQLite database as a cache built from
them. v1.1 added a form-based web editor so you can also create,
edit, and delete recipes without leaving the browser — but the
editor writes to the same `.md` files. The DB never owns anything
the markdown doesn't.

Where the rules-based ingredient parser falls down — and recipes
in the wild are full of edge cases — there's an
[opt-in LLM fallback](docs/llm-fallback.md) that routes the
remainder through Claude Haiku, caches results forever, and never
phones home at request time.

## What it does

- **Browse** by category, search across titles + ingredients +
  method + notes (FTS5).
- **Scale** any recipe up or down. The math respects fractions,
  rounds count-nouns ("3 eggs × 1.5 → ~5 eggs"), and the same
  formatter runs in both PHP and JS — verified by a parity test.
- **Shopping list** across multiple recipes at once with
  aggregation by aisle (produce, dairy, baking, pantry, …),
  unit-class conversion, and a shareable URL.
- **Cooking mode** — distraction-free big-text view, tappable
  timer phrases that start countdowns, Wake Lock to keep the
  screen on, sessionStorage-backed step bookmark.
- **Cross-linking** — explicit `[[recipe-slug]]` references plus
  auto-detection of bare titles in notes prose, plus a Jaccard-
  similarity "Similar recipes" section, plus an [/recipes index
  page](docs/screenshots/recipes-index.png) that visualizes the
  whole cross-link graph for the maintainer.
- **Editing** — create new recipes or edit existing ones in a
  form-based editor with live preview, sortable ingredient rows,
  sub-groups, and a raw-markdown mode for power users. Unparsed
  lines surface in a "Verify these" section with a one-click
  Convert-to-structured action. See
  [docs/editing.md](docs/editing.md) for the workflow.

## Screenshots

### Recipe detail page — Hawaiian Rolls
![Recipe detail — Hawaiian Rolls](docs/screenshots/hawaiian-rolls.png)

Timer phrases and temperatures get visually distinct pills.
Ingredients sub-group by `### Headers`. Sections that aren't
present (libation, notes) just don't render.

### Cooking mode with a running timer
![Cooking mode — Hawaiian Rolls step 19 with a 20-minute timer running](docs/screenshots/cooking-mode.png)

One step at a time, large type. Tap a timer phrase to start it;
the active-timers stripe shows the countdown across step
navigation. Wake Lock keeps the phone screen on; sessionStorage
remembers where you were if the page reloads.

### Shopping list — three recipes aggregated
![Shopping list — 3 recipes aggregated by aisle](docs/screenshots/shopping-list.png)

Add multiple recipes, optionally at different scales, and they
aggregate into one ordered list grouped by aisle. The "(Apple Pie,
Honey Oat Bread)" tag in parentheses shows which recipes
contributed each line.

### Cross-link index page
![/recipes index showing cross-link graph](docs/screenshots/recipes-index.png)

A maintainer's view of every recipe and its outgoing/incoming
cross-references. Useful for spotting "isolated" recipes that
could use a `[[ref]]`.

### Search results
![Search results for 'bread'](docs/screenshots/search.png)

FTS5 across title, ingredient lines, method text, and notes.
Sub-second on this corpus.

### Form-mode editor with live preview
![Form-mode editor — Honey Oat Bread](docs/screenshots/p11e-form-honey-oat.png)

Every field is bound to a live preview pane on the right. Reorder
ingredient rows by drag, toggle to raw markdown for power-user
edits, save back to the same `.md` file.

### Creating a new recipe with live slug derivation
![New recipe form with slug preview](docs/screenshots/p11g-slug-preview.png)

Type a title; the slug auto-derives below it
(`My Sandwich Bread → my-sandwich-bread`). The slug is editable
pre-save and becomes immutable after — slug-as-filename is the
stable identity for cross-references.

## Architecture

```
                                        Web UI (browse, search, cook)
                                                ↑
recipes/*.md  →  RecipeParser  →  SQLite cache (FTS5 + see-also)
   (canonical)    (rules-first)            ↑
       ↑               ↓                   │
       │           IngredientLLMParser  →  ingredient_llm_cache
       │           (Phase 9, opt-in)        (hits permanent,
       │                                     misses 30-day TTL)
       │
       └──  Web editor  →  RecipeFileWriter  →  RecipeReindexer
            (form/raw)     (atomic write)      (single-recipe DB update)
```

- **Parser-first**: every recipe goes through `RecipeParser` first.
  Rules-based, deterministic, no external dependencies. Most lines
  parse cleanly.
- **LLM fallback**: lines the rules-based parser can't structure
  (section headers, parentheticals, unconventional phrasings) get
  routed to Claude Haiku as a batch. Results live forever in
  `ingredient_llm_cache`; misses tombstone for 30 days so future
  model improvements get picked up. The fallback is **indexer-only**
  — no live API calls during page rendering.
- **Editor as another writer**: the web editor (v1.1) goes through
  the same `RecipeFileWriter` (atomic rename, slug validation,
  containment check) as a hypothetical CLI importer would. After a
  save, `RecipeReindexer::reindexOne` surgically updates just that
  recipe's DB slice — no full corpus rebuild. The `.md` file on
  disk remains the source of truth; deleting `database.sqlite` and
  running `make reindex` reproduces the entire DB from the markdown.
- **Cache, not source**: `make reindex` truncates and rebuilds the
  whole SQLite database from the markdown. You can delete
  `database/database.sqlite` and lose nothing material.
- **Self-hosted**: one Docker container, one SQLite file, the
  recipes/ directory mounted in. No external services required
  unless you opt in to the LLM fallback.

More detail: [docs/llm-fallback.md](docs/llm-fallback.md) for the
LLM architecture, [docs/recipe-format.md](docs/recipe-format.md)
for the markdown spec.

## Running it yourself

The intended deployment is your own LAN — a Raspberry Pi, a NAS, a
spare laptop, whatever can run Docker. There's no public-deployment
story; the app has no auth and treats every visitor as
fully-privileged.

**Prerequisites:** Docker + Docker Compose.

**First boot:**

```sh
git clone https://github.com/kbennett2000/recipe-machine.git
cd recipe-machine
cp .env.example .env
touch database/database.sqlite     # required for the bind mount
make rebuild                       # build + boot + migrate
make reindex                       # parse recipes/ into the cache
```

Open <http://localhost:8000>. To access from other devices on the
LAN, replace `localhost` with the host's LAN IP (e.g.
`http://192.168.1.42:8000`).

**Add a recipe:**

Two paths. The web editor (v1.1) is easier for one-offs:

1. Open `http://localhost:8000/recipes/new` in a browser.
2. Type a title, pick a category, fill in ingredients + method.
3. Click "Create recipe" — the file lands at
   `recipes/<category>/<slug>.md` and the index updates.

Or the terminal-first workflow that still works exactly as it did
in v1.0:

```sh
$EDITOR recipes/desserts/your-recipe.md
make reindex
```

The markdown file on disk is the source of truth either way —
the editor is just another writer. See
[docs/recipe-format.md](docs/recipe-format.md) for the markdown
format and [docs/editing.md](docs/editing.md) for the web editor
workflow.

**Enable the LLM fallback (optional):**

Set in `.env`:

```env
RECIPE_MACHINE_LLM_FALLBACK=true
ANTHROPIC_API_KEY=sk-ant-...
```

Then `docker compose exec app php artisan recipes:reindex --with-llm`.

Cost is ~$0.013 per 30-recipe corpus pass (Claude Haiku pricing).
Subsequent reindexes hit the cache for free.

## The recipe format

Frontmatter + standard markdown sections. The
[full spec](docs/recipe-format.md) covers the details, but the
short version is:

```markdown
---
title: Honey Oat Bread
category: breads
slug: honey-oat-bread
servings: "1 loaf (~12 slices)"
yields: 12
cook_time: "40m"
oven_temp: "350F"
libation: "Semi-sweet mead—honey loves honey."
---

## Ingredients

- 3 cups flour
- 2 1/4 tsp instant yeast
- 1 1/4 cups warm milk
- Salt to taste

## Method

1. Knead, rise 1–1½ hours, shape into loaf pan, rise 45–60 min.
2. Bake **35–40 minutes at 350°F**.
```

Sub-groups (`### Pancakes`, `### Filling`) let you scope
ingredient lists to parts of a multi-component recipe. Cross-
references via `[[other-slug]]` resolve to links if the slug
exists.

## Development

See [docs/dev-workflow.md](docs/dev-workflow.md) for the full
loop. Quick reference:

```sh
make test       # full PHPUnit suite (498 tests, ~10s)
make parity     # PHP↔JS formatter parity check
make reindex    # rebuild SQLite from recipes/
make fresh      # nuke + reindex everything
make shell      # bash inside the container
```

## Future work

Documented at [TODO.md](TODO.md). The big-ticket items past v1.1:
a synonym table for ingredient deduplication, jump-to-step picker
for long recipes, swipe gestures for mobile cooking mode, phone-
specific first-draft data-entry UX in the editor, and a few prompt
+ cache refinements for the LLM fallback. Nothing blocking the
day-to-day "look up a recipe and cook it" workflow.

## Credits

Fonts (Fraunces, Inter) are SIL OFL and self-hosted under
[public/fonts/](public/fonts/). Built collaboratively with Claude
Code as the engineer and a separate Claude conversation as the
product owner — see [docs/credits.md](docs/credits.md) for the
full attribution and the why behind that workflow.

## License

[MIT](LICENSE).
