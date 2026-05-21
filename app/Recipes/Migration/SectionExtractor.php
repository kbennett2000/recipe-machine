<?php

declare(strict_types=1);

namespace App\Recipes\Migration;

/**
 * Detects and pulls out the four canonical sections from a source-codex
 * recipe body: ingredients, method, notes, libation.
 *
 * The source corpus uses inconsistent conventions:
 *
 *   - **Ingredients** can be:
 *       1. `### Ingredients` / `#### Ingredients:` (explicit header — most reliable)
 *       2. `**Ingredients**` bold-standalone line
 *       3. A bullet list immediately after the recipe title (default — bread codex)
 *
 *   - **Method** can be:
 *       1. `### Method` / `### Instructions` / `### Directions` (any hash level, optional colon)
 *       2. `**Method**:` inline prose paragraph (bread codex)
 *
 *   - **Notes / Bonus**: `### Notes`, `### Bonus`, or `**Notes**`.
 *
 *   - **Libation**: `**Libation**: <prose>` — usually a one-liner.
 *
 * Precedence (committed; do NOT change without updating the tests):
 *   For each section, explicit hash-headers win over bold-line markers,
 *   which win over inline-prose markers, which win over the bullet-list
 *   default. This means a recipe with both `### Ingredients` and an
 *   implicit leading bullet list uses the explicit header — the bullet
 *   list inside the explicit section, not the one before it.
 *
 * Output: arrays of strings (lines), one array per section. Empty arrays
 * mean the section was not found in the source.
 */
final class SectionExtractor
{
    /**
     * @return array{
     *   ingredients: array<string>,
     *   method: array<string>,
     *   notes: array<string>,
     *   bonus: array<string>,
     *   libation: ?string,
     * }
     */
    public function extract(string $body): array
    {
        $body = str_replace(["\r\n", "\r"], "\n", trim($body));
        if ($body === '') {
            return $this->empty();
        }

        $lines = explode("\n", $body);
        $sections = $this->locateExplicitSections($lines);

        // Libation: usually a one-liner like `**Libation**: ...`. Search even
        // if it's inside another section since the codex frequently puts it
        // at the bottom of the recipe.
        $libation = $this->extractLibation($lines);

        // Ingredients fallback: if no explicit section, take bullet list at top.
        // The fallback also returns any preamble lines it skipped past — those
        // are content the writer put before the bullets (intro prose, unrecognized
        // sub-headers like `#### How to Create Thy Royal Starter`). Route the
        // preamble into ## Notes when ## Notes is otherwise empty, so writer
        // content never gets silently dropped (graceful-degradation principle,
        // spec section c).
        if ($sections['ingredients'] === null) {
            $fallback = $this->extractLeadingBulletsAndPreamble($lines);
            $sections['ingredients'] = $fallback['bullets'];
            if ($fallback['preamble'] !== [] && ($sections['notes'] === null || $sections['notes'] === [])) {
                $sections['notes'] = $fallback['preamble'];
            }
        }

        // Method fallback: look for `**Method**: <prose>` inline.
        if ($sections['method'] === null) {
            $sections['method'] = $this->extractInlineMethod($lines);
        }

        return [
            'ingredients' => $sections['ingredients'] ?? [],
            'method'      => $sections['method'] ?? [],
            'notes'       => $sections['notes'] ?? [],
            'bonus'       => $sections['bonus'] ?? [],
            'libation'    => $libation,
        ];
    }

    /**
     * Pull out `### Header` / `#### Header:` / `**Header**` standalone sections.
     * Each found section becomes an array of body lines (header excluded).
     *
     * Header alias matching is permissive:
     *   - Trailing colon stripped:           `### Ingredients:`           → "ingredients"
     *   - Colon-suffixed sub-label peeled:   `### Bonus: Equipment Notes` → "bonus"
     *   - Parentheticals stripped:           `### Ingredients (makes 8)`  → "ingredients"
     *
     * When a recognized header has a sub-label (`Bonus: Equipment Notes`),
     * the sub-label is preserved as a `### <Sub-Label>` line at the top of
     * the section's buffer so the renderer can keep it as a readable
     * sub-header under `## Notes`.
     *
     * @param  array<string>  $lines
     * @return array<string,?array<string>>  keys: ingredients|method|notes|bonus
     */
    private function locateExplicitSections(array $lines): array
    {
        $aliases = [
            'ingredients' => 'ingredients',
            'method'      => 'method',
            'instructions'=> 'method',
            'directions'  => 'method',
            'steps'       => 'method',
            'notes'       => 'notes',
            'note'        => 'notes',
            'bonus'       => 'bonus',
        ];

        $sections = ['ingredients' => null, 'method' => null, 'notes' => null, 'bonus' => null];
        $current = null;
        $buffer = [];

        $flush = function () use (&$current, &$buffer, &$sections) {
            if ($current !== null) {
                while (count($buffer) && trim($buffer[0]) === '') {
                    array_shift($buffer);
                }
                while (count($buffer) && trim(end($buffer)) === '') {
                    array_pop($buffer);
                }
                if ($sections[$current] === null) {
                    $sections[$current] = $buffer;
                }
            }
            $current = null;
            $buffer = [];
        };

        foreach ($lines as $line) {
            // `## Foo` / `### Foo:` / `#### Foo (...):` — any hash count 2–6.
            if (preg_match('/^#{2,6}\s+(.+?)\s*$/', $line, $m)) {
                $rawTitle = trim($m[1]);
                // Normalize: strip parentheticals first, then peel off colon-suffix sub-label.
                $noParens = (string) preg_replace('/\s*\([^)]*\)\s*/', ' ', $rawTitle);
                $noParens = trim((string) preg_replace('/\s+/', ' ', $noParens));
                $firstSeg = strstr($noParens, ':', before_needle: true);
                $titleKey = mb_strtolower(trim($firstSeg !== false ? $firstSeg : rtrim($noParens, ':')));

                if (isset($aliases[$titleKey])) {
                    $flush();
                    $current = $aliases[$titleKey];
                    // Preserve the post-colon sub-label as a sub-header inside the section.
                    if ($firstSeg !== false) {
                        $subLabel = trim(substr($rawTitle, strlen($firstSeg) + 1));
                        if ($subLabel !== '') {
                            $buffer[] = "### {$subLabel}";
                            $buffer[] = '';
                        }
                    }
                    continue;
                }
                // Unrecognized header inside a known section → sub-group marker (preserve).
                if ($current !== null) {
                    $buffer[] = $line;
                }
                continue;
            }

            // Standalone `**Section**` marker on its own line.
            if (preg_match('/^\*\*([A-Za-z]+)\*\*\s*:?\s*$/', $line, $m)) {
                $title = mb_strtolower(trim($m[1]));
                if (isset($aliases[$title])) {
                    $flush();
                    $current = $aliases[$title];
                    continue;
                }
            }

            // Inline `**Section**: <content>` bold marker — section transition.
            // The bread codex uses `**Method**: <prose>` (sometimes followed by
            // bullets) and `**Libation**: <prose>` lines. Without this branch,
            // such lines pollute whichever section was active before them.
            if (preg_match('/^\*\*([A-Za-z]+)\*\*\s*:\s*(.*)$/', $line, $m)) {
                $title = mb_strtolower(trim($m[1]));
                if (isset($aliases[$title])) {
                    $flush();
                    $current = $aliases[$title];
                    $tail = trim($m[2]);
                    if ($tail !== '') {
                        $buffer[] = $tail;
                    }
                    continue;
                }
                // Unrecognized bold marker (e.g. **Libation**, **Libation while feeding**).
                // Flush the current section so the marker line doesn't pollute it;
                // the line itself is consumed (extractLibation handles libation independently).
                $flush();
                continue;
            }

            if ($current !== null) {
                $buffer[] = $line;
            }
        }
        $flush();

        return $sections;
    }

    /**
     * Default-case extraction: take the first contiguous bullet list found
     * anywhere in the body. Skips leading non-bullet content (intro prose,
     * unrecognized headers like `#### How to Create ...`) and then takes
     * consecutive bullets, stopping at the first non-bullet after them.
     *
     * Phase 2B.1: previously this gave up at the first non-bullet line.
     * Phase 2B.2: also returns the lines we walked past, so the Migrator
     * can route them into ## Notes instead of dropping them silently.
     *
     * @param  array<string>  $lines
     * @return array{bullets: array<string>, preamble: array<string>}
     */
    private function extractLeadingBulletsAndPreamble(array $lines): array
    {
        $bullets = [];
        $preamble = [];
        $seenBullet = false;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || preg_match('/^[-=]{3,}$/', $trimmed)) {
                if ($seenBullet) {
                    break;
                }
                if ($preamble !== []) {
                    $preamble[] = '';
                }
                continue;
            }
            if (preg_match('/^[-*+]\s+/', $trimmed)) {
                $bullets[] = $trimmed;
                $seenBullet = true;
                continue;
            }
            // Indented continuation of a previous bullet.
            if ($seenBullet && preg_match('/^\s+\S/', $line)) {
                $bullets[count($bullets) - 1] .= ' '.$trimmed;
                continue;
            }
            if ($seenBullet) {
                // Non-bullet after bullets → end of the implicit list.
                break;
            }
            // Pre-bullet non-bullet content — capture as preamble.
            $preamble[] = rtrim($line);
        }
        // Strip trailing blanks from preamble.
        while ($preamble !== [] && trim(end($preamble)) === '') {
            array_pop($preamble);
        }
        return ['bullets' => $bullets, 'preamble' => $preamble];
    }

    /**
     * Default-case method extraction: look for `**Method**: <prose>`.
     *
     * The bread codex has two patterns:
     *   - Inline prose only:           `**Method**: Knead, rise, shape, bake.`
     *   - Inline prose + bulleted steps following immediately (Big Soft Pretzels).
     *
     * When both are present, we prefer the bulleted steps — they're the
     * authoritative version; the inline prose is a one-line summary.
     *
     * @param  array<string>  $lines
     * @return array<string>  Steps, ready for the renderer. Single-element
     *                         array means the prose blob will get sentence-split;
     *                         multi-element means each entry is already one step.
     */
    private function extractInlineMethod(array $lines): array
    {
        $prose = [];
        $bullets = [];
        $collecting = false;
        foreach ($lines as $line) {
            if (! $collecting) {
                if (preg_match('/^\*\*(method|instructions|directions)\*\*\s*:\s*(.*)$/i', $line, $m)) {
                    $collecting = true;
                    if (trim($m[2]) !== '') {
                        $prose[] = trim($m[2]);
                    }
                }
                continue;
            }
            $trimmed = trim($line);
            if ($trimmed === '') {
                if ($bullets !== []) {
                    break;
                }
                // Blank line before bullets → method capture ends.
                break;
            }
            if (preg_match('/^\*\*[A-Za-z]+\*\*\s*:/', $trimmed)) {
                break;
            }
            if (preg_match('/^[-*+]\s+/', $trimmed)) {
                $bullets[] = $trimmed;
                continue;
            }
            if ($bullets !== []) {
                // Non-bullet after we started bulleting → done.
                break;
            }
            $prose[] = $trimmed;
        }
        if ($bullets !== []) {
            return $bullets;
        }
        return $prose === [] ? [] : [implode(' ', $prose)];
    }

    /**
     * Find a `**Libation**: <prose>` line anywhere in the body and return the prose.
     *
     * @param  array<string>  $lines
     */
    private function extractLibation(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (preg_match('/^\s*\*\*libation\*\*\s*:\s*(.+?)\s*$/i', $line, $m)) {
                return trim($m[1]);
            }
        }
        return null;
    }

    private function empty(): array
    {
        return [
            'ingredients' => [],
            'method'      => [],
            'notes'       => [],
            'bonus'       => [],
            'libation'    => null,
        ];
    }
}
