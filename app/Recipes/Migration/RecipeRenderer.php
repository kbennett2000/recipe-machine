<?php

declare(strict_types=1);

namespace App\Recipes\Migration;

/**
 * Renders a normalized recipe (frontmatter + sectioned body) into the
 * final markdown file format defined in docs/recipe-format.md v1.6.
 *
 * Frontmatter formatting is conservative: scalars that need quoting get
 * quoted (anything containing `:`, `#`, em-dash, leading digit, etc.);
 * arrays use flow syntax `[a, b, c]` when short.
 *
 * Method-step splitting: when the source gave us a single prose blob,
 * split on `. ` followed by a capital letter — handles the bread-codex
 * "**Method**: Knead, rise 1–1½ hours, shape into loaf pan. Bake..."
 * shape without false-splitting decimal numbers like "1.5 cups".
 */
final class RecipeRenderer
{
    /** Field emission order in the output frontmatter. */
    private const FIELD_ORDER = [
        'title', 'category', 'slug',
        'servings', 'yields',
        'prep_time', 'cook_time', 'total_time',
        'oven_temp',
        'difficulty',
        'tags',
        'libation',
        'source',
        'references',
    ];

    /**
     * Step strings shorter than this (after split) raise a "suspicious split"
     * warning so the user can hand-review.
     */
    private const SUSPICIOUS_SHORT_STEP = 15;
    private const SUSPICIOUS_LONG_STEP = 400;

    /**
     * @param  array<string,mixed>  $frontmatter
     * @param  array<string>  $ingredientLines  Raw bullet lines from source (with bullet marker; will be normalized).
     * @param  array<string>  $methodInput  Either source lines (one per step) or a one-element array containing inline prose.
     * @param  array<string>  $notesLines  Source lines for the notes section, preserved verbatim.
     * @return array{markdown: string, warnings: array<string>}
     */
    public function render(array $frontmatter, array $ingredientLines, array $methodInput, array $notesLines = []): array
    {
        $warnings = [];

        $yamlBlock = $this->renderFrontmatter($frontmatter);

        $ingredientsBlock = $this->renderIngredients($ingredientLines);
        if (trim($ingredientsBlock) === "## Ingredients\n") {
            $warnings[] = 'No ingredients detected in source — emitting empty ## Ingredients section.';
        }

        [$methodBlock, $methodWarnings] = $this->renderMethod($methodInput);
        $warnings = array_merge($warnings, $methodWarnings);
        if (trim($methodBlock) === "## Method") {
            $warnings[] = 'No method content detected in source — emitting empty ## Method section.';
        }

        $notesBlock = $this->renderNotes($notesLines);

        $body = $yamlBlock."\n".$ingredientsBlock."\n".$methodBlock;
        if ($notesBlock !== '') {
            $body .= "\n".$notesBlock;
        }
        // Final newline so editors don't whine.
        if (! str_ends_with($body, "\n")) {
            $body .= "\n";
        }

        return ['markdown' => $body, 'warnings' => $warnings];
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function renderFrontmatter(array $data): string
    {
        $lines = ['---'];
        foreach (self::FIELD_ORDER as $field) {
            if (! array_key_exists($field, $data) || $data[$field] === null) {
                continue;
            }
            $value = $data[$field];
            if (is_array($value)) {
                $lines[] = $this->formatArrayField($field, $value);
            } elseif (is_int($value) || is_float($value)) {
                $lines[] = "$field: $value";
            } else {
                $lines[] = "$field: ".$this->formatScalar((string) $value);
            }
        }
        $lines[] = '---';
        return implode("\n", $lines)."\n";
    }

    private function formatArrayField(string $field, array $values): string
    {
        if ($values === []) {
            return "$field: []";
        }
        $rendered = array_map(fn ($v) => $this->formatScalar((string) $v), $values);
        $inline = "$field: [".implode(', ', $rendered).']';
        if (strlen($inline) <= 90) {
            return $inline;
        }
        $block = ["$field:"];
        foreach ($rendered as $v) {
            $block[] = "  - $v";
        }
        return implode("\n", $block);
    }

    /**
     * Quote a YAML scalar when it contains characters that would confuse the
     * parser, or when bare it might be misinterpreted as null/bool/number.
     */
    private function formatScalar(string $value): string
    {
        // Always quote if it could be misread as a YAML literal.
        if (preg_match('/^(true|false|null|yes|no|on|off|~|\d.*)$/i', $value)) {
            return '"'.str_replace('"', '\\"', $value).'"';
        }
        // Quote if it contains any "fragile" punctuation.
        if (preg_match('/[:#&*!|>\'"`@%,\[\]{}]/u', $value)) {
            return '"'.str_replace('"', '\\"', $value).'"';
        }
        // Em dash → safer to quote (parser handles unquoted em dashes fine,
        // but writers cut and paste these into other tools, so be defensive).
        if (str_contains($value, '—')) {
            return '"'.str_replace('"', '\\"', $value).'"';
        }
        return $value;
    }

    /**
     * @param  array<string>  $lines
     */
    private function renderIngredients(array $lines): string
    {
        $out = ['## Ingredients', ''];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || preg_match('/^[-=_*]{3,}$/', $trimmed)) {
                continue;
            }
            // Sub-group header passes through verbatim.
            if (preg_match('/^#{2,6}\s+/', $trimmed)) {
                // Force `###` for sub-groups in ingredient context.
                $title = (string) preg_replace('/^#+\s+/', '', $trimmed);
                $title = (string) preg_replace('/:$/', '', trim($title));
                $out[] = '';
                $out[] = "### $title";
                continue;
            }
            // Normalize bullet marker to `- `.
            $body = (string) preg_replace('/^\s*[-*+]\s+/', '', $trimmed);
            $out[] = "- $body";
        }
        return implode("\n", $out)."\n";
    }

    /**
     * Method renderer. Accepts either:
     *   - An array of one element containing a prose blob (split on sentences); or
     *   - An array of multiple lines (one per step; numbered/bulleted markers stripped).
     *
     * @param  array<string>  $methodInput
     * @return array{0: string, 1: array<string>}
     */
    private function renderMethod(array $methodInput): array
    {
        $warnings = [];
        $steps = [];

        if (count($methodInput) === 1 && ! preg_match('/^\s*(?:[-*+]|\d+[\.)])\s+/', $methodInput[0])) {
            // Inline prose blob — split on sentence boundaries.
            $steps = $this->splitProseIntoSteps($methodInput[0]);
        } else {
            // Already-listed steps. Strip leading bullet/number markers.
            // Filter out markdown horizontal rules (`---`, `===`, etc.) that
            // sometimes leak in from the source as section dividers.
            foreach ($methodInput as $raw) {
                $stripped = (string) preg_replace('/^\s*(?:[-*+]|\d+[\.)])\s+/', '', $raw);
                $stripped = trim($stripped);
                if ($stripped === '' || preg_match('/^[-=_*]{3,}$/', $stripped)) {
                    continue;
                }
                $steps[] = $stripped;
            }
        }

        // Warn on suspicious steps.
        foreach ($steps as $i => $step) {
            $len = mb_strlen($step);
            if ($len < self::SUSPICIOUS_SHORT_STEP) {
                $warnings[] = sprintf('Method step %d looks suspiciously short (%d chars) — review: %s',
                    $i + 1, $len, $step);
            }
            if ($len > self::SUSPICIOUS_LONG_STEP) {
                $warnings[] = sprintf('Method step %d looks suspiciously long (%d chars) — review the split.',
                    $i + 1, $len);
            }
        }

        $out = ['## Method', ''];
        foreach ($steps as $i => $step) {
            $out[] = ($i + 1).'. '.$step;
        }
        return [implode("\n", $out)."\n", $warnings];
    }

    /**
     * Sentence split that avoids breaking on "1.5 cups" or "Mr. Smith":
     * splits on `. `, `! `, or `? ` followed by an uppercase letter or "If".
     *
     * @return array<string>
     */
    public function splitProseIntoSteps(string $prose): array
    {
        $prose = trim($prose);
        if ($prose === '') {
            return [];
        }
        // Normalize internal whitespace.
        $prose = (string) preg_replace('/\s+/', ' ', $prose);

        // Split on sentence boundary: `. ` / `! ` / `? ` followed by a capital letter.
        // Preserves the punctuation by using a lookbehind/lookahead approach.
        $parts = preg_split('/(?<=[.!?])\s+(?=[A-Z])/u', $prose);
        if ($parts === false) {
            return [$prose];
        }
        return array_values(array_filter(array_map('trim', $parts), fn ($s) => $s !== ''));
    }

    /**
     * @param  array<string>  $lines
     */
    private function renderNotes(array $lines): string
    {
        $lines = array_values(array_filter($lines, fn ($l) => trim($l) !== ''));
        if ($lines === []) {
            return '';
        }
        $out = ['## Notes', ''];
        foreach ($lines as $line) {
            $out[] = rtrim($line);
        }
        return implode("\n", $out)."\n";
    }
}
