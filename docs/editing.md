# Editing recipes

v1.1 added a web editor at `/recipes/<slug>/edit` (existing recipes)
and `/recipes/new` (brand-new recipes). The editor is one of three
ways to put a recipe into the corpus — they all write to the same
`recipes/<category>/<slug>.md` files on disk and end up in the same
SQLite cache.

## When to use what

### Form mode (default for the web editor)

Best for: tweaking an ingredient amount, adding a tag, fixing a
typo in a method step, creating a recipe from scratch.

Every field is a labeled input bound to a live preview pane on the
right. Ingredient rows are drag-reorderable. Sub-groups
(`### Pancakes`, `### Filling`) have their own management section.
Modifier and note hints have `?` tooltips explaining what goes there
(post-comma vs post-em-dash content).

### Raw mode (toggle from form mode)

Best for: structural edits that the form makes awkward (moving a
whole sub-group around, pasting in markdown from another source,
fixing a YAML quirk by hand).

The raw editor is a textarea with syntax cues — frontmatter keys
get one color, list bullets another, headings another. Tab indents,
Shift+Tab unindents. Toggle back to form mode and the editor parses
your raw markdown into the structured form; if it can't parse you
get an alert and stay in raw mode.

### $EDITOR (terminal, the v1.0 workflow)

Best for: bulk operations (find/replace across many recipes), git
operations (cherry-pick a recipe from another branch), or just
preferring vim over a web form.

```sh
$EDITOR recipes/desserts/your-recipe.md
make reindex
```

Identical end-state to the web editor. All three paths write the
same canonical markdown.

## The slug is immutable

Once a recipe is saved, its slug is fixed. The edit form shows a
`🔒 immutable` pill next to the slug field on existing recipes.

**Why:** the slug is both the filename
(`recipes/<category>/<slug>.md`) and the cross-reference key
(`[[honey-oat-bread]]` in another recipe's notes). Renaming a slug
would break any inbound references and orphan the underlying file
in git history. If you really need to rename, do it in the terminal
with `git mv` so the rename is recorded properly.

**On the create form** (`/recipes/new`), the slug auto-derives from
the title (`My Sandwich Bread → my-sandwich-bread`) and stays
editable until the first save. After that, the immutable rule
kicks in.

## "Verify these" — handling unparsed lines

The rules-based parser tries hard but recipes in the wild have weird
shapes ("Coarse pretzel salt (or kosher salt)", "1½ tsp instant
yeast — for denser pretzels"). Lines it can't structure show up in a
"Verify these" section at the bottom of the ingredients editor.

Each unparsed line has a "Convert to structured" button. Clicking
it tries three paths server-side:

1. **Rules parser, second pass.** Sometimes a line failed during
   bulk indexing but parses fine in isolation.
2. **LLM cache lookup.** If the recipe was previously reindexed
   with `--with-llm`, the LLM's structured parse is cached and
   instantly available. A ✨ "LLM cache" amber badge appears on
   the converted row so you know to glance over the fields.
3. **Best-effort fallback.** If neither produces a structured
   parse, the raw line is dropped into the ingredient field as a
   starting point and the row gets a ⚠ "best-effort — review
   before saving" badge.

The endpoint never calls the live LLM API — clicks are free.
Bulk LLM parsing still goes through `make reindex` with
`--with-llm`.

## The stale-file banner

If someone (or something) modifies the recipe's `.md` file on
disk while you have the editor open — another editor window,
`git pull`, a script — the editor will notice within ~10 seconds
and show an amber banner at the top:

> The file has changed on disk since you opened it. Your edits
> may overwrite changes made elsewhere. [Reload] or click Save
> below to accept your version.

**Reload** re-fetches the file (loses your unsaved edits).
**Save** overwrites with your version (loses the disk change —
you can recover it from `git diff HEAD` since the markdown is
versioned). The banner is informational, not blocking — you
choose which version wins.

The polling endpoint is `/recipes/<slug>/edit/mtime` and the
poll interval is hard-coded to 10 seconds. Polling stops once
the banner fires (no point repeating once you know).

## Form ↔ raw mode preserves work in progress

Toggling between modes round-trips through the server:

- **Form → Raw:** the form state is serialized to canonical
  markdown and dropped into the raw textarea.
- **Raw → Form:** the raw textarea is parsed back into form
  state.

If you've made changes in form mode and click Raw, you'll see
them as markdown. Edit there, toggle back, and your edits are
parsed into the structured fields. The "dirty" indicator on the
mode toggle (an amber dot) tracks unsaved changes vs. the
initial state.

**Edge case:** if raw markdown is malformed (missing required
frontmatter fields, unbalanced quotes), toggling back to form
mode will alert and refuse. Fix the raw markdown and try again.
For the brand-new recipe flow specifically, the round-trip is
skipped while the form is empty — there's nothing meaningful to
transfer, so we avoid the edge case entirely.

## Auth, trust, deployment

The editor inherits the rest of the app's trust model: **none.**
Recipe Machine has no authentication and treats every visitor as
fully privileged. The intended deployment is a single-user LAN
(your home network, your Raspberry Pi, your spare laptop).

This means:

- Don't expose the editor to the public internet.
- Don't bind the container to a public IP. The default host
  port (`APP_PORT=8000`, see `.env.example`) bound to
  `localhost` is fine; if you need LAN access, bind to the LAN
  IP, not `0.0.0.0`.
- Don't assume anyone with the URL needs to be authenticated to
  destroy data — they don't. They can delete recipes via the
  Delete button. (Git history will save you, but only if you've
  been committing.)

If you ever want to share an instance on the open internet, you'll
need to add an auth layer (or front the container with a reverse
proxy that does). Not in scope for v1.x.

## File-system effects

Saving a recipe via the editor does, in order:

1. Validate slug + category against the writer's regex
   ([RecipeFileWriter::SLUG_PATTERN](../app/Recipes/Files/RecipeFileWriter.php)).
2. Atomically write the markdown to a temp file in the same
   directory, then rename onto the target. The original (if any)
   is untouched until the rename succeeds.
3. Call `RecipeReindexer::reindexOne(slug)` to surgically update
   just that recipe's row in SQLite (+ FTS5 row, + see-also
   relationships involving the changed recipe). No full corpus
   rebuild.
4. Redirect to `/recipes/<slug>` with a flash banner.

Delete is the same in reverse: file removed from disk via
`unlink()`, recipe row dropped from SQLite, cross-references that
pointed at it become unresolved (but the reference rows
themselves are preserved as history).

If the disk is full, the filesystem is read-only, or the temp
file can't be renamed for any reason, the original file is left
intact and the editor shows an error banner — you don't lose
work.

## Workflow tips

- **The same recipe in two windows is fine** thanks to the
  stale-file banner. Save in one, the other notices.
- **Use the live preview to catch parser failures early.** If
  you're typing a line that the preview shows in italics on the
  right ("Coarse pretzel salt..."), the parser couldn't
  structure it — fix the line before saving, or convert it later
  via Verify these.
- **Drag the handle on touch, click-and-drag on desktop.** On a
  phone, long-press (~500ms) on the `⋮⋮` handle before dragging
  — this prevents drag-on-tap from interfering with scroll. On a
  desktop, the drag is instant.
- **Mobile editing is reference-editing quality, not first-draft
  quality.** Tweaking an existing recipe in the kitchen works
  fine. Typing a 30-ingredient recipe from scratch on a phone is
  doable but slow; do that on a laptop or tablet.
