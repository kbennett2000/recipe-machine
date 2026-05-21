<?php

declare(strict_types=1);

namespace App\Recipes\Migration;

/**
 * Splits a big source-codex markdown file into per-recipe SourceRecipe sections.
 *
 * Responsibilities:
 *   - Find the top-level `# Title` H1 for category inference.
 *   - Split on `## ` H2 headers; everything between two H2s is one recipe.
 *   - Strip leading `NN.` numeric prefixes from H2 titles.
 *   - Skip sections that are obviously NOT recipes (TOC, contents).
 *   - Strip markdown image references (`![alt](path)`) from bodies — v1 does
 *     not support recipe images and they'd otherwise pollute the round-trip.
 *
 * Does NOT do: frontmatter mining, body normalization, slug-to-output mapping.
 * Those happen downstream in Migrator.
 */
final class SourceParser
{
    private const SKIP_TITLE_PATTERNS = [
        '/^table\s+of\s+contents$/i',
        '/^contents$/i',
        '/^toc$/i',
        '/^index$/i',
    ];

    /**
     * @return array{title: ?string, recipes: array<SourceRecipe>}
     */
    public function parse(string $markdown): array
    {
        // Normalize line endings so per-line indices stay consistent.
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);

        // Image refs are not supported in v1; strip them before splitting.
        $markdown = (string) preg_replace('/!\[[^\]]*\]\([^)]+\)/u', '', $markdown);

        $lines = explode("\n", $markdown);

        $h1Title = null;
        $recipes = [];
        $current = null;
        $currentLine = 0;

        foreach ($lines as $i => $line) {
            // First H1 wins; later H1s are unusual but we keep the first.
            if ($h1Title === null && preg_match('/^#(?!#)\s+(.+?)\s*$/', $line, $m)) {
                $h1Title = trim($m[1]);
                continue;
            }

            // H2 — start of a new recipe section.
            if (preg_match('/^##(?!#)\s+(.+?)\s*$/', $line, $m)) {
                // Flush previous section.
                if ($current !== null) {
                    $recipes[] = $this->finalize($current, $currentLine);
                }
                $rawTitle = $this->stripNumericPrefix(trim($m[1]));
                $current = ['title' => $rawTitle, 'body' => []];
                $currentLine = $i + 1;
                continue;
            }

            if ($current !== null) {
                $current['body'][] = $line;
            }
            // Lines before the first H2 (after the H1 and any TOC) are discarded.
        }

        if ($current !== null) {
            $recipes[] = $this->finalize($current, $currentLine);
        }

        // Drop TOC-like sections.
        $recipes = array_values(array_filter(
            $recipes,
            fn (SourceRecipe $r) => ! $this->shouldSkip($r->title),
        ));

        return ['title' => $h1Title, 'recipes' => $recipes];
    }

    /**
     * Infer a category slug from an H1 codex title.
     *
     *   "The Official Royal Codex of Kick-Ass Breads"  → "breads"
     *   "Recipes — Sauces, Soups, Entrees & Desserts"  → null (ambiguous;
     *                                                     caller must pass --category explicitly)
     *
     * The inference looks for one of the six recommended category words
     * appearing at the end of the title.
     */
    public function inferCategory(string $h1Title): ?string
    {
        $recommended = ['breads', 'sauces', 'soups', 'entrees', 'desserts', 'seafood'];
        $lower = mb_strtolower($h1Title);

        $matches = [];
        foreach ($recommended as $cat) {
            if (preg_match('/\b'.preg_quote($cat, '/').'\b/', $lower)) {
                $matches[] = $cat;
            }
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * "Honey Oat Bread" → "honey-oat-bread"
     * "50/50 White & Whole Wheat Bread" → "50-50-white-whole-wheat-bread"
     * "Pretzel Bread Loaves (Laugenbrot)" → "pretzel-bread-loaves-laugenbrot"
     */
    public function slugify(string $title): string
    {
        $title = mb_strtolower($title);
        // Strip diacritics/accents the cheap way.
        $title = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $title) ?: $title;
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $title);
        return trim($slug, '-');
    }

    private function finalize(array $current, int $line): SourceRecipe
    {
        return new SourceRecipe(
            title: $current['title'],
            body: trim(implode("\n", $current['body'])),
            sourceLine: $line,
        );
    }

    private function stripNumericPrefix(string $title): string
    {
        return (string) preg_replace('/^\d+\.\s+/', '', $title);
    }

    private function shouldSkip(string $title): bool
    {
        foreach (self::SKIP_TITLE_PATTERNS as $pattern) {
            if (preg_match($pattern, $title)) {
                return true;
            }
        }
        return false;
    }
}
