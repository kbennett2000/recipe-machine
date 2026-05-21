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
| `category`   | yes      | string          | `breads`                      |
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

**`category`** — a lowercase string matching the parent directory (`recipes/<category>/`).

The **recommended** set is: `breads`, `sauces`, `soups`, `entrees`, `desserts`, `seafood`. The UI groups recipes under these six top-level buckets.

The field is **open**, not enum-constrained. Any lowercase string is permitted, but recipes with a category outside the recommended set are rendered under a single "Other" group in the UI (forward note — not a spec rule the parser enforces). Writers should not need to PR the spec to add a new category. If a new category accumulates enough recipes to deserve top-level placement, it can be promoted to the recommended set as a documentation-only change.

Allowed characters: `^[a-z][a-z0-9-]*$` — lowercase ASCII, digits, and hyphens; must start with a letter. The directory `recipes/<category>/` must exist on disk.

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
category: breads
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

Everything between the amount and the ingredient name is the unit (optional). Writers spell units however they like; the parser normalizes each input spelling to a single **canonical form** for the structured output. The writer's original spelling is preserved separately for display.

#### Canonical units

There are four unit classes. Each canonical form is what downstream code (search, scaling, shopping list) sees.

**Volume**

| Canonical | Accepted input spellings (case-insensitive unless noted) |
|-----------|----------------------------------------------------------|
| `tsp`     | `tsp`, `tsps`, `teaspoon`, `teaspoons` |
| `tbsp`    | `tbsp`, `tbsps`, `tbs`, `Tbsp`, `tablespoon`, `tablespoons` |
| `cup`     | `cup`, `cups` |
| `floz`    | `fl oz`, `fl. oz.`, `fl oz.`, `floz`, `fluid ounce`, `fluid ounces` |
| `pint`    | `pint`, `pints`, `pt`, `pts` |
| `quart`   | `quart`, `quarts`, `qt`, `qts` |
| `gallon`  | `gallon`, `gallons`, `gal` |
| `ml`      | `ml`, `mL`, `milliliter`, `milliliters`, `millilitre`, `millilitres` |
| `l`       | `l`, `L`, `liter`, `liters`, `litre`, `litres` |

**Weight**

| Canonical | Accepted input spellings |
|-----------|--------------------------|
| `g`       | `g`, `gram`, `grams`, `gm`, `gms` |
| `kg`      | `kg`, `kilo`, `kilos`, `kilogram`, `kilograms` |
| `oz`      | `oz`, `ounce`, `ounces` (mass context — see "oz disambiguation" below) |
| `lb`      | `lb`, `lbs`, `pound`, `pounds`, `#` |

**Count**

| Canonical | Accepted input spellings |
|-----------|--------------------------|
| `whole`   | *(no input spelling — internal marker)* |

`whole` is the parser's internal unit for unitless countable items. The writer never types `whole`; it appears in the parsed output when there's an amount but no unit token before the ingredient name. Example:

- `3 garlic cloves` → amount=`3`, unit=`whole`, ingredient=`garlic cloves`
- `1 large onion` → amount=`1`, unit=`whole`, ingredient=`large onion`

The canonical for unitless counted items is `whole`. Unknown unit-like tokens (`1 box pasta`, `1 stick butter` if `stick` weren't a count noun) also collapse to `unit: whole` rather than `unit: null` — the parser keeps the structured-output unit slot two-valued (a canonical, or `whole`). See "Unknown unit-like tokens" below for the details.

**Imprecise**

| Canonical    | Accepted input spellings |
|--------------|--------------------------|
| `pinch`      | `pinch`, `pinches`, `a pinch of`, `pinch of` |
| `dash`       | `dash`, `dashes`, `a dash of` |
| `splash`     | `splash`, `splashes`, `a splash of` |
| `drizzle`    | `drizzle`, `drizzled`, `a drizzle of` |
| `handful`    | `handful`, `handfuls`, `a handful of` |
| `sprinkle`   | `sprinkle`, `sprinkles`, `a sprinkle of` |
| `to-taste`   | `to taste`, `to-taste` |
| `as-needed`  | `as needed`, `as-needed` |

**Structured-output shape.** Imprecise quantities go in the **unit slot**, with `amount: null`. The structured fields are:

```
amount:     null
unit:       <one of the eight canonicals above>
ingredient: <name>
```

Example: `a pinch of salt` parses to `{amount: null, unit: "pinch", ingredient: "salt", optional: false}`.

The display layer renders these by reading the unit and producing natural prose ("a pinch of salt", "a handful of arugula", "a drizzle of olive oil"). Writers do not need to know or care about the slot layout — they just type natural English.

**Closed list.** The canonical imprecise units are the closed list above. A token like `smidge`, `glug`, `dollop`, or `knob` (as in "a knob of butter") does **not** get a canonical assignment. Such lines fall through to the unparseable-line rule (see "Unparseable lines" below); they render verbatim and skip structured features like scaling and shopping-list aggregation. Writers who hit this should either rephrase ("a pat of butter" → "1 Tbsp butter") or accept that the line stays raw until Phase 10's LLM fallback gets to it.

#### Single-letter forms not in v1

The single-letter abbreviations `T` (tablespoon), `t` (teaspoon), `c` (cup), and `#` (pound) are **not** supported by the v1 parser. Writers must spell out `tbsp`, `tsp`, `cup`, and `lb` (or their full-word forms).

A line like `1 T salt` will not be recognized as `1 tablespoon salt`. The `T` will fall through as an unrecognized unit-like token; the line parses to `{amount: 1, unit: whole, ingredient: "T salt"}` — technically valid but semantically meaningless. Writers should rephrase.

Rationale: single-letter forms collide with too many ingredient names (`c` with anything starting with c, `T` with case-sensitive readers, `#` with markdown header syntax). The cost of supporting them — case-sensitive matching, special-cased word boundaries, ambiguity with `T`/`t` capitalization — outweighed the benefit for v1. They may be reconsidered in a future version once we have a corpus of real recipes to measure against.

#### `oz` disambiguation (volume vs weight)

The token `oz` could mean fluid ounce (volume) or ounce (weight). The parser disambiguates from context:

- Preceded by a liquid-context cue (`fl`, `fl.`, or one of `fluid`, `fluid ounce`) → `floz`
- Otherwise → `oz` (weight)

When unsure, writers should write `fl oz` for fluid ounce and `oz` for weight.

#### Unknown unit-like tokens

Tokens that don't match any canonical mapping are left as part of the ingredient name. The parser does not invent units. Example: `1 box pasta` — `box` is not a canonical unit, so this parses as `{amount: 1, unit: whole, ingredient: "box pasta"}`. The line is parseable (it has an amount and a name) but the unit slot stays `whole` since there's no canonical unit to assign. Writers should rephrase if precision matters (`1 lb pasta`, or `1 16-oz box pasta`).

This is the same rule that handles `1 large onion` — any amount-bearing line without a canonical unit gets `unit: whole`. The unit slot in v1's structured output is two-valued (a canonical, or `whole`), never `null`.

### Ingredient name and modifier

Everything after the unit is the ingredient name, up to the first comma. After the comma (if any) is the modifier — the preparation state, e.g. `minced`, `softened`, `diced`, `room temperature`, `at room temperature`, `finely chopped`.

```
1 cup butter, softened
^ ^   ^       ^
amt unit ingr. modifier
```

For lines that exercise count-noun folding, the structured output places the count noun in the ingredient name and `unit: whole` in the unit slot — see "Whole / countable items" below. So `3 cloves garlic, minced` parses to `{amount: 3, unit: whole, ingredient: "garlic cloves", modifier: "minced"}`, not `unit: cloves`.

If there's no comma, there's no modifier; everything past the unit is the ingredient name.

### Inline comments

A space-em-dash-space (` — `, U+2014 between two regular spaces) separates the ingredient line from a freeform comment. The parser strips the comment from the structured fields but keeps it for display.

```
- 1 large onion, diced — Vidalia if you can find them
```

Parses to:

- amount: `1`
- unit: `whole` *("large" is part of the ingredient name, not a unit)*
- ingredient: `large onion`
- modifier: `diced`
- comment: `Vidalia if you can find them`

**Why em dash and not `--` or parentheses:** dashes and parens appear naturally inside ingredient names ("all-purpose flour", "(I-)"); using them as comment delimiters causes collisions. The em dash with surrounding spaces is unambiguous, renders cleanly in markdown, and many editors auto-convert `--` to `—`, so writers rarely need to type it directly.

### Typography and unicode

The parser accepts several typographically distinct characters for the same semantic intent. This subsection enumerates them so writers know what's safe.

#### Unicode fractions

The following Unicode fraction characters are recognized and converted to their decimal equivalent for math:

| Glyph | Codepoint | Decimal | Glyph | Codepoint | Decimal |
|-------|-----------|---------|-------|-----------|---------|
| ½     | U+00BD    | 0.5     | ⅙     | U+2159    | 0.16667 |
| ⅓     | U+2153    | 0.33333 | ⅚     | U+215A    | 0.83333 |
| ⅔     | U+2154    | 0.66667 | ⅛     | U+215B    | 0.125   |
| ¼     | U+00BC    | 0.25    | ⅜     | U+215C    | 0.375   |
| ¾     | U+00BE    | 0.75    | ⅝     | U+215D    | 0.625   |
| ⅕     | U+2155    | 0.2     | ⅞     | U+215E    | 0.875   |
| ⅖     | U+2156    | 0.4     |       |           |         |
| ⅗     | U+2157    | 0.6     |       |           |         |
| ⅘     | U+2158    | 0.8     |       |           |         |

Other Unicode fraction-like characters (e.g. ⅐, ⅑, ⅒) are not in v1. Use `1/7` etc. if needed.

#### Range separators in amounts

Three dash characters are all valid range separators inside an amount:

| Character     | Codepoint | Name         |
|---------------|-----------|--------------|
| `-`           | U+002D    | Hyphen-minus |
| `–`           | U+2013    | En dash      |
| `—`           | U+2014    | Em dash      |

All three parse equivalently: `2-3 cups`, `2–3 cups`, and `2—3 cups` all mean "2 to 3 cups". The en dash is the typographically conventional choice for ranges; the parser doesn't care.

**The em dash is overloaded.** It serves two roles in ingredient lines:

1. As a range separator **inside** an amount (no surrounding spaces): `2—3 cups flour`.
2. As an inline-comment delimiter **between** the structured part and a freeform note (with surrounding spaces): `1 large onion, diced — Vidalia if you can find them`.

The parser distinguishes by context: if the em dash sits between two numeric tokens with no whitespace, it's a range separator; if it has whitespace on both sides and follows the ingredient or modifier, it's a comment delimiter. Writers who want to avoid the overload can use `–` (en dash) for ranges and reserve `—` (em dash) for comments — that's the recommended convention.

#### Mixed fractions with unicode

These three forms all parse to 1.5:

- `1½` — integer + unicode fraction, no space (glyphically attached)
- `1 ½` — integer + space + unicode fraction
- `1 1/2` — integer + space + ASCII fraction

Writers should pick one style per file for consistency. The parser doesn't care which.

#### Other Unicode niceties

- **Smart quotes** (`'`, `"`) are accepted in modifiers and comments and preserved as-is. They don't appear in structured fields.
- **Non-breaking spaces** (U+00A0) are treated as regular spaces by the parser.
- **Multiplication sign** `×` (U+00D7) is recognized as "by" in dimensions (e.g. a `9×13 inch pan` in `## Notes`), but is not currently parsed for structured output.

### Whole / countable items

For items without a measurement, the counting noun goes in the ingredient name:

- Preferred: `3 garlic cloves` → amount=`3`, unit=`whole`, ingredient=`garlic cloves`
- Also accepted: `3 cloves garlic` → amount=`3`, unit=`whole`, ingredient=`garlic cloves` (the parser folds the count noun back into the ingredient name)

The parser handles both forms identically; writers won't be consistent and that's fine. Note that `cloves`, `slices`, `sprigs`, `heads`, `bunches`, `cans`, `jars`, `sticks` are recognized as count nouns and folded into the ingredient name rather than living in the unit slot. The canonical unit for any countable item without a measurement is `whole`.

### Optional items

Mark optional ingredients with either or both of these markers:

- **Prefix:** the line begins with `Optional:` (case-insensitive, followed by whitespace).
- **Suffix:** the line ends with `(optional)` (case-insensitive, optionally preceded by whitespace).

Both yield `optional: true` in the parsed output. Structured fields are extracted normally; the `optional` flag is set alongside.

#### Idempotence rule

The parser sets `optional: true` **exactly once per item** regardless of how many markers are present. Order doesn't matter. All of the following parse identically:

- `Optional: 1/2 cup walnuts, chopped`
- `1/2 cup walnuts, chopped (optional)`
- `Optional: 1/2 cup walnuts, chopped (optional)` *(both markers — redundant, but accepted)*
- `optional: 1/2 cup walnuts, chopped (Optional)` *(mixed case)*

All four produce: amount=`1/2`, unit=`cup`, ingredient=`walnuts`, modifier=`chopped`, `optional=true`.

The markers themselves are stripped from the structured fields before parsing — `Optional:` does not become part of the amount, and `(optional)` does not become part of the modifier or a comment. Writers who use both markers don't get punished; they just don't get double-flagged.

### Imprecise quantity line shapes

Imprecise quantity phrases (pinch, dash, handful, drizzle, splash, sprinkle, to taste, as needed — see the canonical-units table above) appear in two natural English patterns:

- **Leading:** `<imprecise> [of] <ingredient>` — *Pinch of salt*, *Pinch salt*, *Handful of arugula*, *Handful arugula*, *Drizzle of olive oil*, *Splash vinegar*, *Sprinkle paprika*. The `of` is optional.
- **Trailing:** `<ingredient>[, ] <imprecise>` — *Salt to taste*, *Olive oil as needed*, *Olive oil, as needed*.

All of these produce the same structured shape: `amount: null`, `unit: <canonical>`, `ingredient: <name>`.

Examples:

- `Salt to taste` → `{amount: null, unit: "to-taste", ingredient: "salt"}`
- `Pinch of salt` → `{amount: null, unit: "pinch", ingredient: "salt"}` (the `of` is consumed)
- `Pinch salt` → `{amount: null, unit: "pinch", ingredient: "salt"}` (no `of`; still parses)
- `Olive oil, as needed` → `{amount: null, unit: "as-needed", ingredient: "olive oil"}` (the comma is consumed; the imprecise phrase lifts to the unit slot even when a comma precedes it)
- `A handful of arugula` → `{amount: null, unit: "handful", ingredient: "arugula"}`
- `A pinch kosher salt` → `{amount: null, unit: "pinch", ingredient: "kosher salt"}`

The parser detects the imprecise phrase **before** falling through to the generic `<amount> <unit> <ingredient>[, <modifier>]` shape, so a comma between ingredient and an imprecise trailing phrase does not get interpreted as a modifier delimiter. Writers should write whichever pattern reads naturally; the parser handles both.

**Which canonicals support leading-without-of:**

| Canonical    | Leading `of` form | Leading bare form | Trailing form |
|--------------|------------------:|------------------:|--------------:|
| `pinch`      | yes               | yes               | n/a           |
| `dash`       | yes               | yes               | n/a           |
| `splash`     | yes               | yes               | n/a           |
| `drizzle`    | yes               | yes               | n/a           |
| `handful`    | yes               | yes               | n/a           |
| `sprinkle`   | yes               | yes               | n/a           |
| `to-taste`   | n/a               | n/a               | yes           |
| `as-needed`  | n/a               | n/a               | yes           |

The two trailing-only canonicals (`to-taste`, `as-needed`) don't appear in leading position because "to taste salt" and "as needed olive oil" aren't natural English. Writers always say "salt to taste" and "olive oil as needed".

### Unparseable lines

Recipes **never** fail to load because of ingredient parse errors. When a line cannot be parsed under any of the rules above — even after canonical normalization — it survives as a raw record and the recipe loads with every other ingredient parsed normally.

#### Structured shape

A parsed line has the structured fields (`amount`, `unit`, `ingredient`, `modifier`, `comment`, `optional`) plus `parsed: true`.

An unparsed line is represented as:

```
{
  "raw": "<original verbatim text, leading and trailing whitespace preserved>",
  "parsed": false
}
```

No structured fields. The position of the line within the ingredient list (including its sub-group, if any) is preserved alongside the record.

#### Behavior

- **Rendering.** Unparsed lines appear in the UI exactly as the writer typed them, in their original position. No reformatting, no "broken" styling.
- **Scaling math.** Unparsed lines are skipped. Scaling the recipe by 2× scales every parsed line; raw lines display unchanged.
- **Shopping list.** Unparsed lines do not contribute to shopping-list aggregation. They appear under the source recipe with a flag so the writer can copy them by hand.
- **Ingredient search.** Unparsed lines do not participate in structured ingredient search ("recipes that contain garlic"). Full-text search still indexes their raw text.
- **Surfacing.** The recipe detail page shows a small, informational indicator: *"(N lines couldn't be auto-parsed)"*. This is **not** an error state — it's a hint that the writer may want to look at those lines if they want the features.

#### LLM fallback — forward note

Phase 10 will route unparsed lines through an LLM fallback. The fallback runs offline (not in the request path) and either:

- Returns a structured parse → the line transitions to `parsed: true` and gets the structured fields.
- Fails again → the line stays `parsed: false`.

In either outcome, the result is **cached forever**, keyed by the raw line text. A future re-parse of the same exact line text never re-pays the LLM cost. (Roadmap note: this references "Phase 10" per the project's working phase plan, which may not match the public roadmap in the README. Treat the phase number as a forward placeholder, not a hard schedule.)

### Graceful degradation — project-wide principle

The "never fail to load; surface broken state informationally" pattern in this section is **not** an ingredient-specific rule — it's a project-wide principle. The same philosophy applies to:

- **Unresolved cross-references** (Section d.1) — `[[ref]]` to a nonexistent recipe renders as plain bold text, no link, no error. Resolves the moment the target recipe lands.
- **Missing optional metadata** (Section a) — the recipe renders with whatever's present; absent optional fields collapse silently, with no placeholder text.
- **Method steps without timer/temperature hints** — still render as plain prose; the cooking-mode highlighting just doesn't fire.
- **Unknown `## headers`** in the body (Section b) — ignored, not errored.

**Principle: graceful degradation over hard failure, everywhere.** A broken, unusual, or partially-specified recipe should always be readable. Structured features (scaling, search, shopping lists, cross-recipe links) can opt out of the unusual parts, but the human-facing display never breaks.

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

**Rendering of unresolved references.** When a `[[ref]]` cannot be resolved to an existing recipe, v1 renders it as plain **bold text** with no hyperlink, no tooltip, and no error. Resolution happens at index time and re-runs on every reindex, so a reference that's broken today becomes a working link the moment the target recipe lands.

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
category: breads
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

## f.1) Scaling math (Phase 5)

Recipes with a numeric `yields` frontmatter field can be scaled live in the
UI: the reader changes the target servings and ingredient amounts
recalculate. The math rules below define how the formatter handles the
results — both server-side (PHP `IngredientFormatter`) and client-side
(`resources/js/ingredient-format.js`). The two implementations must agree
byte-for-byte; a parity test (`composer parity-check`) enforces it.

**Per unit class:**

| Unit class       | How scaled amounts render                                              |
|------------------|------------------------------------------------------------------------|
| `whole`          | Round to nearest 0.5; show `~` prefix if non-integer (`~4.5 eggs`)     |
| Volume / weight  | Snap to common fractions when possible (`1/2`, `1 1/4`); decimal fallback otherwise |
| `null` (no unit) | Same as volume/weight — display the scaled number, no unit            |
| `imprecise`      | **Do not scale**. "A pinch of salt" stays "A pinch of salt" at any factor. |

**The `~` rule for countable items.** When `unit=whole` and the scaled
amount isn't an integer, the formatter prefixes the rendered amount with
`~` to be honest about the math:

| Input                              | Scale | Rendered            |
|------------------------------------|-------|---------------------|
| 3 eggs                             | ×1.5  | `~4.5 eggs`         |
| 1 onion                            | ×2.5  | `~2.5 onions`       |
| 3 eggs                             | ×2    | `6 eggs`            |
| 2 cups flour                       | ×1.5  | `3 cups flour`      |
| 1.5 cups flour                     | ×2    | `3 cups flour`      |

The amount itself is rounded to the nearest 0.5 increment, so the result
is always a clean fraction (5, 5.5, 6, …) — the reader makes the final
"do I add half an egg?" call. There is no auto-rounding to whole units;
that would silently change the proportions of the rest of the recipe.

**Ranges scale both endpoints.** `2-3 cloves garlic` ×2 → `4–6 garlic cloves`.
If either endpoint becomes non-integer under a whole-unit scale, the `~`
prefix applies to the range (`~3–4.5 garlic cloves`).

**Method steps do NOT scale.** "Bake for 35 minutes" stays "Bake for 35
minutes" at any scale factor. Bake times, rise times, and cooking
durations are nonlinear in batch size — scaling a 35-minute bake to
52 minutes for a 1.5× batch would be wrong more often than it'd be right.
Timing decisions stay with the cook.

**Recipes without `yields` have no scaling UI.** They render as a static
display. `yields` is the explicit opt-in.

**Scale persistence.** The user's chosen scale persists per recipe via
browser `sessionStorage` (not `localStorage` — we deliberately scope the
preference to the current browser session). Closing the browser resets all
scales; navigating between recipes during a session keeps each recipe's
scale independently.

---

## g) Open questions / explicit non-decisions

These were considered and deliberately left unspecified in v1. Each is callable out as future work when the use case arrives.

- **Image embedding.** Markdown's `![alt](path)` is fine. The Phase 3 parser ignores images for structured output but preserves them in rendered HTML. Revisit when we have a recipe with a photo.
- **Recipe variants in one file.** One file = one recipe. Variations ("same dough, two fillings") go in two files linked via `references`. A `variant_of:` field may appear later if this gets painful.
- **Nutrition data.** No calories/macros fields. Out of scope for v1; revisit after Phase 6 (shopping list) when ingredient-level metadata becomes useful.
- **Equipment lists.** No `## Equipment` section. Mention equipment in `## Notes` if it matters. A structured section may follow.
- **Explicit timer/temperature syntax.** We rely on regex detection of natural prose ("bake 35–40 minutes at 350°F") rather than tagged syntax like `[timer:35m]`. Keeps writing natural at the cost of some parser fuzziness; the LLM fallback closes the gap.
- **Scaling math for non-divisible items.** When `yields` represents discrete countable units (e.g. `makes ~30 cookies`, `yields: 30`) and the user requests a non-integer multiplier (e.g. 1.5× to make 45 cookies), most ingredients scale cleanly — but what about an ingredient like `1 egg`? Do we scale to `1.5 eggs` and round at display time ("2 eggs")? Round at math time (so the rest of the recipe is computed against `multiplier = 2/1 = 2`, throwing the proportions off)? Or refuse to scale recipes where the math doesn't land on whole units? **Deferred to Phase 6 (shopping list / scaling).** Decision should fall out of the shopping-list aggregation work, since the same "what's a fractional egg" question shows up there.
- **Worked-example sub-groups.** The user-supplied worked example (Honey Oat Bread) has no sub-component groups. Sub-groups are demonstrated inline in Section b instead. A future recipe like "French Silk Pie" or "Pan Pizza" is a better canonical sub-group example.
- **Reference resolution timing.** `[[slug]]` references may point to recipes that don't exist yet. The parser records the reference; resolution is a Phase 2/3 indexing concern, not a parse error.
- **Multi-language / units (metric vs imperial).** v1 stores whatever the writer used. Dual-unit display and conversion are post-MVP.
- **Recipe-level `created_at` / `updated_at`.** Filesystem `mtime` is the source of truth in v1.
