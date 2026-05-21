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
        if ($sections['ingredients'] === null) {
            $sections['ingredients'] = $this->extractLeadingBullets($lines);
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
     * @param  array<string>  $lines
     * @return array<string,?array<string>>  keys: ingredients|method|notes|bonus
     */
    private function locateExplicitSections(array $lines): array
    {
        // Map of normalized section name → output key.
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
                // Trim leading/trailing blank lines but preserve interior structure.
                while (count($buffer) && trim($buffer[0]) === '') {
                    array_shift($buffer);
                }
                while (count($buffer) && trim(end($buffer)) === '') {
                    array_pop($buffer);
                }
                // First-write-wins: if the same section was located twice, keep the first.
                if ($sections[$current] === null) {
                    $sections[$current] = $buffer;
                }
            }
            $current = null;
            $buffer = [];
        };

        foreach ($lines as $line) {
            // `### Foo` / `#### Foo:` / `## Foo` — any hash count, optional trailing colon.
            if (preg_match('/^#{2,6}\s+(.+?)\s*:?\s*$/', $line, $m)) {
                $title = mb_strtolower(trim($m[1]));
                if (isset($aliases[$title])) {
                    $flush();
                    $current = $aliases[$title];
                    continue;
                }
                // An unrecognized header inside a known section is treated as a sub-group
                // marker (e.g. `### Glaze` under `## Ingredients`). Preserve the line.
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

            // Inside an explicit section — accumulate.
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
     * Phase 2B.1 hardening: previously this gave up at the first non-bullet
     * line, which meant recipes-within-recipes (the sourdough starter case)
     * lost their ingredient bullets because the source had an unrecognized
     * `#### Sub-Header` between the body's start and the ingredient list.
     * Now we walk past leading non-bullets until bullets appear, then take
     * the contiguous run.
     *
     * @param  array<string>  $lines
     * @return array<string>
     */
    private function extractLeadingBullets(array $lines): array
    {
        $bullets = [];
        $seenBullet = false;
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || preg_match('/^[-=]{3,}$/', $trimmed)) {
                if ($seenBullet) {
                    // Blank line after bullets ends the list.
                    break;
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
            // Non-bullet content: skip while we haven't found any bullets yet,
            // but stop once we have (the run ends at the first non-bullet line).
            if ($seenBullet) {
                break;
            }
            // Leading prose / unrecognized sub-header — keep scanning forward.
            continue;
        }
        return $bullets;
    }

    /**
     * Default-case method extraction: look for an inline `**Method**: <prose>`
     * pattern. The prose continues until a blank line or another `**Foo**:`.
     *
     * @param  array<string>  $lines
     * @return array<string>  (a single-element array containing the prose,
     *                        or empty if not found)
     */
    private function extractInlineMethod(array $lines): array
    {
        $prose = [];
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
                break;
            }
            // Another bold-prefix section ends the method capture.
            if (preg_match('/^\*\*[A-Za-z]+\*\*\s*:/', $trimmed)) {
                break;
            }
            $prose[] = $trimmed;
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
