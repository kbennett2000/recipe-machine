# Recipe Format Specification

> **Version 1.0 — Phase 1**
> **Layout:** YAML frontmatter + GitHub-flavored markdown body

This document defines the on-disk format that recipe files in `recipes/<category>/<slug>.md` must follow so the Phase 3 parser can extract structured data from them. The format aims to be:

- **Easy to write by hand.** Plain markdown, no exotic syntax.
- **Easy to parse with regex.** The happy-path line shapes match simple patterns.
- **Tolerant of human inconsistency.** When the regex layer fails on a line, an LLM fallback takes over — but the spec is tuned so that fallback fires rarely.

---

## a) Frontmatter schema

Every recipe begins with a YAML frontmatter block delimited by `---` lines.

| Field        | Required | Type            | Example                       |
|--------------|----------|-----------------|-------------------------------|
| `title`      | yes      | string          | `Honey Oat Bread`             |
| `category`   | yes      | enum            | `bread`                       |
| `slug`       | no       | string          | `honey-oat-bread`             |
| `servings`   | no       | string          | `"1 loaf"`                    |
| `prep_time`  | no       | duration string | `20m`                         |
| `cook_time`  | no       | duration string | `40m`                         |
| `total_time` | no       | duration string | `3h`                          |
| `oven_temp`  | no       | string          | `350F`                        |
| `tags`       | no       | array of string | `[yeast, honey, loaf]`        |
| `libation`   | no       | string          | `"Semi-sweet mead"`           |
| `source`     | no       | string          | URL or attribution            |
| `difficulty` | no       | enum            | `easy`, `medium`, `hard`      |
| `yields`     | no       | number          | `12`                          |
| `references` | no       | array of string | `[pasta-sauce, pie-crust]`    |

### Field details

**`category`** — one of: `bread`, `sauce`, `soup`, `entree`, `dessert`, `seafood`. Must match the parent directory (`recipes/<category>/`). Singular form; the directory names are plural (`recipes/breads/`) but the enum value is `bread`.

**`slug`** — lowercase, hyphen-separated. If omitted, derived from the filename stem (`honey-oat-bread.md` → `honey-oat-bread`). When present, it must match the filename stem; a mismatch is a parse error.

**`servings` vs `yields`** — these answer different questions and can coexist.

- `servings` is **for humans to read.** A free-form string like `"1 loaf"`, `"8 servings"`, `"makes ~30 cookies"`. This is what the UI displays in the recipe header.
- `yields` is **for math.** A single integer representing the count of the natural countable unit the recipe produces. The scaling feature (Phase 5) uses `yields` to compute "I want 24 cookies, multiply everything by 24/30."

A loaf of bread can be `servings: "1 loaf"` (display) and `yields: 12` (slices, for per-slice nutrition or "make 2× the recipe" math). If only one is given, the parser sets the other where possible. If both are absent, scaling is disabled for that recipe.

**Duration strings** — match the regex `^(\d+h)?(\d+m)?(\d+s)?$` with at least one component non-empty. Examples: `20m`, `1h`, `1h30m`, `2h15m`. Seconds are rarely useful and supported only for completeness. Whitespace is not allowed.

**`oven_temp`** — `<number><F|C>`, no degree symbol, no space, in frontmatter. Examples: `350F`, `175C`. Body prose can use the full form (`350°F`) for readability; frontmatter uses the compact form for ease of parsing.

**`tags`** — free-form strings, lowercased, hyphenated for multi-word tags. Recommended categories include cooking method (`baking`, `grilling`), dietary (`vegetarian`, `gluten-free`), occasion (`weeknight`, `holiday`). Not validated; the parser passes them through verbatim.

**`difficulty`** — one of: `easy`, `medium`, `hard`. Coarse on purpose.

**`source`** — URL (`https://...`) or freeform attribution (`Adapted from Smitten Kitchen`). No structured author/publisher fields in v1.

**`references`** — slugs (not titles) of related recipes that should appear as "see also" links. Alternative to inline `[[double-bracket]]` references in the body. See Section d.

### Example frontmatter

```yaml
---
title: Honey Oat Bread
category: bread
slug: honey-oat-bread
servings: "1 loaf"
yields: 12
prep_time: 20m
cook_time: 40m
total_time: 3h
oven_temp: 350F
difficulty: easy
tags: [yeast, honey, loaf]
libation: "Semi-sweet mead — honey loves honey"
---
```

### YAML notes

Frontmatter must be valid YAML. In practice:

- Quote strings that contain `:`, `#`, `[`, `]`, `{`, `}`, `,`, `&`, `*`, `!`, `|`, `>`, `'`, `"`, `%`, `@`, or `` ` ``.
- The `libation` string is a common offender — when in doubt, wrap it in double quotes.
- Use `[...]` flow syntax for short arrays and block syntax (`- ...`) for long ones; both are valid.

---

## b) Body structure

After the frontmatter, the body uses `##` headers to mark sections. Sections may appear in any order, but the suggested order is Ingredients → Method → Notes → Libation.

### `## Ingredients` (required)

Items as a markdown bulleted list (`- ` or `* `). Both bullet markers are accepted; pick one and stay consistent within a file.

```markdown
## Ingredients

- 3 cups all-purpose flour
- 1 tsp salt
- 3 cloves garlic, minced
```

#### Sub-component groups

A `### <Group Name>` header **under** `## Ingredients` opens a sub-component group. All bullets between that header and the next `###` or `##` belong to the group.

```markdown
## Ingredients

### Dough
- 3 cups flour
- 1 tsp salt
- 1 1/4 cups warm water

### Filling
- 1 cup walnuts, chopped
- 1/2 cup brown sugar

### Glaze
- 1/4 cup honey
- 1 Tbsp butter, melted
```

A recipe with no `###` sub-headers has a single implicit group; the parser may represent it as `null` or `"default"`.

### `## Method` (required)

**Canonical header: `## Method`.** Aliases the parser must also accept: `## Instructions`, `## Directions`.

Steps as a numbered list **or** a bulleted list (writer's preference). Each top-level item is one step. Nested bullets are sub-actions of the parent step — rendered indented, parsed as part of the parent step's text.

```markdown
## Method

1. Mix the dry ingredients in a large bowl.
2. Add the wet ingredients and knead until smooth.
   - If the dough is sticky, add flour one tablespoon at a time.
   - If it's dry, add a splash of water.
3. Let rise 1 hour.
```

In this example, step 2 has two sub-actions; the parsed step's body text includes both bullets.

### `## Notes` (optional)

Free-form prose or bullets. Tips, variations, troubleshooting, history, serving suggestions. No structure imposed.

### `## Libation` (optional)

Prose alternative to the frontmatter `libation` field, for when the writer wants more than a one-liner. If both the frontmatter field and the body section are present, **the body section wins**.

### Unknown headers

Any `##` header the parser doesn't recognize is ignored, along with its content. Don't rely on this — stick to the recognized set.

---

## c) Ingredient line conventions

The canonical line shape:

```
<amount> <unit> <ingredient>[, <modifier>] [ — <comment>]
```

Examples that parse trivially under the rules below:

- `2 cups all-purpose flour`
- `1 tsp salt`
- `3 cloves garlic, minced`
- `1/2 cup unsalted butter, softened`
- `1 large onion, diced — Vidalia if you can find them`

### Amount

Accepted amount formats:

| Format            | Example     |
|-------------------|-------------|
| Integer           | `2`, `12`   |
| Decimal           | `0.5`, `1.25` |
| Fraction          | `1/2`, `3/4` |
| Mixed fraction    | `1 1/2`, `2 1/4` (single space between integer and fraction) |
| Unicode fraction  | `½`, `¼`, `¾`, `⅓`, `⅔`, `⅛`, `⅜`, `⅝`, `⅞` |
| Unicode mixed     | `1½`, `2¼` (no space — glyphically attached) |
| Range             | `2-3`, `4–5` (hyphen-minus or en-dash, amounts on both sides) |

Starting regex (Phase 3 will refine):

```
^(?:\d+\s+\d+/\d+|\d+/\d+|\d+(?:\.\d+)?|[¼½¾⅓⅔⅛⅜⅝⅞]|\d+[¼½¾⅓⅔⅛⅜⅝⅞])
(?:\s*[\-–]\s*(?:\d+\s+\d+/\d+|\d+/\d+|\d+(?:\.\d+)?|[¼½¾⅓⅔⅛⅜⅝⅞]|\d+[¼½¾⅓⅔⅛⅜⅝⅞]))?
```

### Unit

Standard recipe units the parser recognizes (case-insensitive, plural-tolerant):

- Volume: `tsp`, `tbsp`/`Tbsp`/`T`, `cup`, `oz` (fluid context), `ml`, `l`, `pint`, `quart`, `gallon`
- Mass: `oz` (mass context), `lb`, `g`, `kg`
- Countable: `clove`, `slice`, `sprig`, `head`, `bunch`, `can`, `jar`, `stick`

The parser normalizes case and pluralization internally (e.g., `cups` → `cup`) but preserves the writer's spelling for display. Unknown unit-like tokens are left as part of the ingredient name.

### Ingredient name and modifier

Everything after the unit is the ingredient name, up to the first comma. After the comma (if any) is the modifier — the preparation state, e.g. `minced`, `softened`, `diced`, `room temperature`, `at room temperature`, `finely chopped`.

```
3 cloves garlic, minced
^   ^      ^       ^
amt unit   ingr.   modifier
```

If there's no comma, there's no modifier; everything past the unit is the ingredient name.

### Inline comments

A space-em-dash-space (` — `, U+2014 between two regular spaces) separates the ingredient line from a freeform comment. The parser strips the comment from the structured fields but keeps it for display.

```
- 1 large onion, diced — Vidalia if you can find them
```

Parses to:

- amount: `1`
- unit: *(none — "large" is part of the ingredient name)*
- ingredient: `large onion`
- modifier: `diced`
- comment: `Vidalia if you can find them`

**Why em dash and not `--` or parentheses:** dashes and parens appear naturally inside ingredient names ("all-purpose flour", "(I-)"); using them as comment delimiters causes collisions. The em dash with surrounding spaces is unambiguous, renders cleanly in markdown, and many editors auto-convert `--` to `—`, so writers rarely need to type it directly.

### Whole / countable items

For items without a measurement, the counting noun goes in the ingredient name:

- Preferred: `3 garlic cloves` → amount=`3`, unit=`(none)`, ingredient=`garlic cloves`
- Also accepted: `3 cloves garlic` → amount=`3`, unit=`cloves`, ingredient=`garlic`

The parser handles both; writers won't be consistent and that's fine. Downstream code treats `clove` as a countable unit either way.

### Optional items

Mark optional ingredients in either of two ways:

- Prefix: `Optional: 1/2 cup walnuts, chopped`
- Suffix: `1/2 cup walnuts, chopped (optional)`

Both yield `optional: true` in the parsed output. Structured fields are extracted normally; the `optional` flag is set alongside.

### "To taste" / "pinch" / "as needed"

These are amount-like tokens with no numeric value. The canonical forms put them where the amount would go:

- `Salt to taste` → amount=`to taste`, unit=`(none)`, ingredient=`salt`
- `Pinch of salt` → amount=`pinch`, unit=`(none)`, ingredient=`salt` (the `of` is consumed)
- `Olive oil, as needed` → ingredient=`olive oil`, modifier=`as needed`

Recognized fuzzy amounts: `pinch`, `dash`, `splash`, `handful`, `drizzle`, `to taste`, `as needed`. This is precisely the kind of line where the LLM fallback earns its keep; writers should write naturally.

---

## d) Method step conventions

### One step per top-level item

Numbered (`1. `) or bulleted (`- `), writer's choice. Each top-level item is one step. Nested bullets under a step are sub-actions and are concatenated into the parent step's body text.

### Timer hints

Any number-or-range followed by a time unit is a candidate timer in cooking mode. Starting regex:

```
\b(\d+(?:[\.\/]\d+)?[¼½¾⅓⅔⅛⅜⅝⅞]?
  (?:\s*[\-–]\s*\d+(?:[\.\/]\d+)?[¼½¾⅓⅔⅛⅜⅝⅞]?)?)
\s*(min(?:ute)?s?|hours?|hrs?|h|seconds?|secs?|s)\b
```

Matches:

- `15 minutes`
- `15-20 minutes`
- `1 hour`
- `1–1½ hours`
- `45 min`
- `30s`

For ranges, the parser captures both endpoints; the UI can default to the low end and let users adjust.

### Temperature mentions

Highlighted in cooking mode. Starting regex:

```
\b(\d{2,4})\s*°?\s*([FC])\b
```

Matches: `350°F`, `350 F`, `175C`, `175 C`. The degree symbol is optional; spacing is flexible.

---

## d.1) Cross-references to other recipes

Two ways to link from one recipe to another:

**1. Inline, in prose** — wrap the target recipe's slug or title in double brackets:

```markdown
Spread [[pasta-sauce]] over the shells.
Serve with a side of [[Pumpkin Soup]].
```

The parser tries the bracketed text first as a slug match, then as a case-insensitive title match. Use this for natural prose mentions in `## Method` or `## Notes`.

**2. Frontmatter `references` list** — when the relationship is "see also" rather than inline:

```yaml
references: [pasta-sauce, pie-crust]
```

Both can coexist; the parser unions and dedupes them by slug. Recommendation: brackets for inline natural mentions; frontmatter for related-recipe sidebars.

References to recipes that don't exist yet are recorded but flagged as unresolved. Resolution (does the slug exist? was the target renamed?) is a Phase 2/3 indexing concern, not a parse-time error.

---

## e) File naming

```
recipes/<category-directory>/<slug>.md
```

Rules:

- `<category-directory>` is the plural form: `breads`, `sauces`, `soups`, `entrees`, `desserts`, `seafood`. The `category` frontmatter field is the singular form.
- `<slug>` matches `^[a-z0-9][a-z0-9-]*$`: lowercase ASCII letters and digits, hyphens as separators, no leading hyphen, no spaces, no punctuation.
- Extension is `.md`.
- If the frontmatter `slug` field is present, it must equal the filename stem.

Examples:

- `recipes/breads/honey-oat-bread.md`
- `recipes/desserts/french-silk-pie.md`
- `recipes/seafood/shrimp-and-grits.md`

---

## f) Worked example: Honey Oat Bread

The file at `recipes/breads/honey-oat-bread.md`:

````markdown
---
title: Honey Oat Bread
category: bread
slug: honey-oat-bread
servings: "1 loaf"
yields: 12
prep_time: 20m
cook_time: 40m
total_time: 3h
oven_temp: 350F
difficulty: easy
tags: [yeast, honey, loaf]
libation: "Semi-sweet mead — honey loves honey"
---

## Ingredients

- 3 cups flour
- 3/4 cup rolled oats
- 1/4 cup honey
- 2 Tbsp butter
- 1 1/2 tsp salt
- 2 1/4 tsp instant yeast
- 1 1/4 cups warm milk

## Method

1. Mix and knead the dough until smooth and elastic.
2. Cover and let rise 1–1½ hours, until doubled.
3. Shape into a loaf pan and let rise another 45–60 minutes.
4. Bake at 350°F for 35–40 minutes, until the top is deep golden and the loaf sounds hollow when tapped.
5. Brush the top with butter while still warm for a soft crust.

## Notes

This is a forgiving dough — it tolerates a little extra flour and a little extra rise time. Pairs nicely with [[pumpkin-soup]] for dunking.
````

This example exercises:

- All required frontmatter fields plus most optionals.
- A bulleted ingredient list (no sub-groups; see Section g).
- A numbered `## Method` with three timer hints (`1–1½ hours`, `45–60 minutes`, `35–40 minutes`) and a temperature (`350°F`).
- A `## Notes` section containing an inline cross-reference (`[[pumpkin-soup]]`) — a forward link to a recipe that doesn't exist yet, deliberately, to demonstrate that unresolved references are tolerated.
- A `libation` value in frontmatter (one-line form), not the `## Libation` body section.

---

## g) Open questions / explicit non-decisions

These were considered and deliberately left unspecified in v1. Each is callable out as future work when the use case arrives.

- **Image embedding.** Markdown's `![alt](path)` is fine. The Phase 3 parser ignores images for structured output but preserves them in rendered HTML. Revisit when we have a recipe with a photo.
- **Recipe variants in one file.** One file = one recipe. Variations ("same dough, two fillings") go in two files linked via `references`. A `variant_of:` field may appear later if this gets painful.
- **Nutrition data.** No calories/macros fields. Out of scope for v1; revisit after Phase 6 (shopping list) when ingredient-level metadata becomes useful.
- **Equipment lists.** No `## Equipment` section. Mention equipment in `## Notes` if it matters. A structured section may follow.
- **Explicit timer/temperature syntax.** We rely on regex detection of natural prose ("bake 35–40 minutes at 350°F") rather than tagged syntax like `[timer:35m]`. Keeps writing natural at the cost of some parser fuzziness; the LLM fallback closes the gap.
- **Unit normalization at parse time.** The parser preserves what the writer wrote (`Tbsp` stays `Tbsp`, `tablespoon` stays `tablespoon`). Normalization to a canonical form happens at query/scaling time, not at parse time.
- **Worked-example sub-groups.** The user-supplied worked example (Honey Oat Bread) has no sub-component groups. Sub-groups are demonstrated inline in Section b instead. A future recipe like "French Silk Pie" or "Pan Pizza" is a better canonical sub-group example.
- **Reference resolution timing.** `[[slug]]` references may point to recipes that don't exist yet. The parser records the reference; resolution is a Phase 2/3 indexing concern, not a parse error.
- **Multi-language / units (metric vs imperial).** v1 stores whatever the writer used. Dual-unit display and conversion are post-MVP.
- **Recipe-level `created_at` / `updated_at`.** Filesystem `mtime` is the source of truth in v1.
