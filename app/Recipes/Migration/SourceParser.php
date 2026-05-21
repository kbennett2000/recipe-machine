<?php

declare(strict_types=1);

namespace App\Recipes\Migration;

/**
 * Splits a big source-codex markdown file into per-recipe SourceRecipe sections.
 *
 * Supports two source structures (auto-detected):
 *
 *   Flat: `## Recipe 1`, `## Recipe 2`, ...           (the breads codex)
 *   Hierarchical: `## Category` + `### Recipe`        (the recipes codex)
 *
 * Hierarchical detection fires when 2+ of the H2 titles match the recommended
 * category words (`breads`, `sauces`, `soups`, `entrees`, `desserts`, `seafood`).
 *
 * Title normalizations applied during the split:
 *   - Leading `NN.` numeric prefixes are stripped.
 *   - A small allowlist of TITLE_FIXUPS rewrites known one-off titles
 *     (e.g. "Bonus: The Sacred Sourdough Starter Itself" → "Sourdough Starter").
 *
 * Pre-processing applied to the whole input:
 *   - CRLF normalization.
 *   - Markdown image refs `![alt](path)` stripped (v1 doesn't support images).
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
     * Map of pattern → canonical title. Applied to titles BEFORE slugification.
     * Order matters; first match wins.
     */
    private const TITLE_FIXUPS = [
        // The bread codex has `## Bonus: The Sacred Sourdough Starter Itself`
        // at H2 level (sibling to other recipes). We want it to land at
        // recipes/breads/sourdough-starter.md so the boule's references: [sourdough-starter]
        // resolves cleanly.
        '/^bonus:.*sourdough\s*starter/i' => 'Sourdough Starter',
    ];

    private const RECOMMENDED_CATEGORIES = [
        'breads', 'sauces', 'soups', 'entrees', 'desserts', 'seafood',
    ];

    /**
     * @return array{title: ?string, recipes: array<SourceRecipe>, mode: string}
     */
    public function parse(string $markdown): array
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $markdown = (string) preg_replace('/!\[[^\]]*\]\([^)]+\)/u', '', $markdown);

        $lines = explode("\n", $markdown);

        // First pass: collect H1 + H2 titles to decide flat vs hierarchical.
        $h1Title = null;
        $h2Titles = [];
        foreach ($lines as $line) {
            if ($h1Title === null && preg_match('/^#(?!#)\s+(.+?)\s*$/', $line, $m)) {
                $h1Title = trim($m[1]);
                continue;
            }
            if (preg_match('/^##(?!#)\s+(.+?)\s*$/', $line, $m)) {
                $h2Titles[] = trim($m[1]);
            }
        }

        $hierarchical = $this->isHierarchical($h2Titles);
        $recipes = $hierarchical
            ? $this->splitHierarchical($lines)
            : $this->splitFlat($lines);

        // Drop TOC-like sections.
        $recipes = array_values(array_filter(
            $recipes,
            fn (SourceRecipe $r) => ! $this->shouldSkip($r->title),
        ));

        return [
            'title' => $h1Title,
            'recipes' => $recipes,
            'mode' => $hierarchical ? 'hierarchical' : 'flat',
        ];
    }

    /**
     * @param  array<string>  $h2Titles
     */
    private function isHierarchical(array $h2Titles): bool
    {
        $catCount = 0;
        foreach ($h2Titles as $title) {
            $lower = mb_strtolower(trim($title));
            $cleaned = (string) preg_replace('/[^a-z]+/', '', $lower);
            foreach (self::RECOMMENDED_CATEGORIES as $cat) {
                if ($cleaned === $cat) {
                    $catCount++;
                    break;
                }
            }
        }
        return $catCount >= 2;
    }

    /**
     * Flat split (breads-codex style): every `## ` is a recipe.
     *
     * @param  array<string>  $lines
     * @return array<SourceRecipe>
     */
    private function splitFlat(array $lines): array
    {
        $recipes = [];
        $current = null;
        $currentLine = 0;

        foreach ($lines as $i => $line) {
            if (preg_match('/^#(?!#)\s+/', $line)) {
                // H1 — skip; already captured in first pass.
                continue;
            }
            if (preg_match('/^##(?!#)\s+(.+?)\s*$/', $line, $m)) {
                if ($current !== null) {
                    $recipes[] = $this->finalize($current, $currentLine);
                }
                $current = ['title' => $this->normalizeTitle($m[1]), 'body' => [], 'category' => null];
                $currentLine = $i + 1;
                continue;
            }
            if ($current !== null) {
                $current['body'][] = $line;
            }
        }
        if ($current !== null) {
            $recipes[] = $this->finalize($current, $currentLine);
        }
        return $recipes;
    }

    /**
     * Hierarchical split (recipes-codex style): `## Category` then `### Recipe`.
     *
     * @param  array<string>  $lines
     * @return array<SourceRecipe>
     */
    private function splitHierarchical(array $lines): array
    {
        $recipes = [];
        $current = null;
        $currentLine = 0;
        $currentCategory = null;

        foreach ($lines as $i => $line) {
            if (preg_match('/^#(?!#)\s+/', $line)) {
                continue;
            }
            // H2 — category divider.
            if (preg_match('/^##(?!#)\s+(.+?)\s*$/', $line, $m)) {
                if ($current !== null) {
                    $recipes[] = $this->finalize($current, $currentLine);
                    $current = null;
                }
                $rawTitle = trim($m[1]);
                $maybeCat = $this->categoryFromTitle($rawTitle);
                if ($maybeCat !== null) {
                    $currentCategory = $maybeCat;
                } else {
                    // Non-category H2 (e.g. `## Bonus: ...`) — fall through to treat as a recipe.
                    $current = [
                        'title' => $this->normalizeTitle($rawTitle),
                        'body' => [],
                        'category' => $currentCategory,
                    ];
                    $currentLine = $i + 1;
                }
                continue;
            }
            // H3 — recipe within current category.
            if (preg_match('/^###(?!#)\s+(.+?)\s*$/', $line, $m)) {
                if ($current !== null) {
                    $recipes[] = $this->finalize($current, $currentLine);
                }
                $current = [
                    'title' => $this->normalizeTitle($m[1]),
                    'body' => [],
                    'category' => $currentCategory,
                ];
                $currentLine = $i + 1;
                continue;
            }
            if ($current !== null) {
                $current['body'][] = $line;
            }
        }
        if ($current !== null) {
            $recipes[] = $this->finalize($current, $currentLine);
        }
        return $recipes;
    }

    /**
     * Map an H2 category title to its category slug, or null if not a known category.
     */
    private function categoryFromTitle(string $title): ?string
    {
        $cleaned = mb_strtolower(trim($title));
        $cleaned = (string) preg_replace('/[^a-z]+/', '', $cleaned);
        foreach (self::RECOMMENDED_CATEGORIES as $cat) {
            if ($cleaned === $cat) {
                return $cat;
            }
        }
        return null;
    }

    /**
     * Infer a category slug from an H1 codex title (flat-mode only).
     *
     *   "The Official Royal Codex of Kick-Ass Breads"  → "breads"
     *   "Recipes — Sauces, Soups, Entrees & Desserts"  → null (ambiguous)
     */
    public function inferCategory(string $h1Title): ?string
    {
        $lower = mb_strtolower($h1Title);
        $matches = [];
        foreach (self::RECOMMENDED_CATEGORIES as $cat) {
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
            category: $current['category'] ?? null,
        );
    }

    private function normalizeTitle(string $title): string
    {
        $title = trim($title);
        $title = (string) preg_replace('/^\d+\.\s+/', '', $title);
        foreach (self::TITLE_FIXUPS as $pattern => $replacement) {
            if (preg_match($pattern, $title)) {
                return $replacement;
            }
        }
        return $title;
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
