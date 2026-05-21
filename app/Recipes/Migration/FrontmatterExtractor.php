<?php

declare(strict_types=1);

namespace App\Recipes\Migration;

/**
 * Mines per-recipe frontmatter fields out of source body content
 * heuristically. Returns null for any field it can't confidently extract;
 * downstream code emits those as absent rather than guessed.
 *
 * Patterns are intentionally conservative — when in doubt, leave it null
 * and let the user fill it in.
 */
final class FrontmatterExtractor
{
    /**
     * @param  array<string>  $methodLines  Output of SectionExtractor::extract()['method'].
     * @param  array<string>  $bodyLines  Full recipe body (used for libation if not already lifted).
     * @return array{
     *   oven_temp: ?string,
     *   cook_time: ?string,
     *   servings: ?string,
     *   yields: ?int,
     *   libation: ?string,
     * }
     */
    public function extract(array $methodLines, array $bodyLines = []): array
    {
        $methodText = implode("\n", $methodLines);
        $bodyText = implode("\n", $bodyLines);

        return [
            'oven_temp' => $this->extractOvenTemp($methodText),
            'cook_time' => $this->extractCookTime($methodText),
            'servings'  => $this->extractServings($methodText, $bodyText),
            'yields'    => $this->extractYields($methodText, $bodyText),
            'libation'  => $this->extractLibation($bodyText),
        ];
    }

    /**
     * Find the first temperature mention. Normalize to compact `350F` form.
     */
    private function extractOvenTemp(string $text): ?string
    {
        if (preg_match('/\b(\d{2,4})\s*°?\s*([FC])\b/u', $text, $m)) {
            return $m[1].strtoupper($m[2]);
        }
        return null;
    }

    /**
     * Find a bake/cook/simmer duration. Range upper bound wins
     * ("35–40 minutes" → 40m). Hours convert too ("1–1½ hours" → 90m;
     * the ½ is approximated to 30m).
     *
     * Matches:
     *   bake/cook/simmer/roast/fry for NN minutes
     *   bake/cook for NN-NN minutes
     *   NN minutes  (when adjacent to a bake-cue word in the same sentence)
     */
    private function extractCookTime(string $text): ?string
    {
        // Minutes — range: "35–40 minutes" / "35-40 min".
        if (preg_match('/(?:bake|cook|simmer|roast|fry|broil)[^.\n]*?(\d+)\s*[-–—to]+\s*(\d+)\s*(?:min(?:ute)?s?)\b/iu', $text, $m)) {
            return ((int) $m[2]).'m';
        }
        // Minutes — single: "bake 40 minutes".
        if (preg_match('/(?:bake|cook|simmer|roast|fry|broil)[^.\n]*?(\d+)\s*(?:min(?:ute)?s?)\b/iu', $text, $m)) {
            return ((int) $m[1]).'m';
        }
        // Hours — range.
        if (preg_match('/(?:bake|cook|simmer|roast)[^.\n]*?(\d+)\s*[-–—to]+\s*(\d+)\s*(?:hours?|hrs?|h)\b/iu', $text, $m)) {
            return ((int) $m[2]).'h';
        }
        // Hours — single.
        if (preg_match('/(?:bake|cook|simmer|roast)[^.\n]*?(\d+)\s*(?:hours?|hrs?|h)\b/iu', $text, $m)) {
            return ((int) $m[1]).'h';
        }
        return null;
    }

    /**
     * Find a servings/yield prose mention. Returns the original substring.
     *
     * Recognized patterns:
     *   yields N servings
     *   makes N cookies
     *   makes about N (loaves|servings|...)
     *   (makes 8 large or 12 medium)
     *   serves N
     */
    private function extractServings(string $methodText, string $bodyText): ?string
    {
        $text = $methodText !== '' ? $methodText : $bodyText;
        if (preg_match('/yields?\s+(?:about\s+)?[\d~]+[\s\w\d,()\/-]*?\b(servings?|cookies|loaves|loaf|biscuits|rolls|portions?|cups|pancakes|waffles|pieces)\b/i', $text, $m)) {
            return trim($m[0]);
        }
        if (preg_match('/makes?\s+(?:about\s+|approximately\s+|~)?[\d~]+[\s\w\d,()\/-]*?\b(servings?|cookies|loaves|loaf|biscuits|rolls|portions?|cups|pancakes|waffles|pieces)\b/i', $text, $m)) {
            return trim($m[0]);
        }
        if (preg_match('/serves\s+(?:about\s+)?\d+/i', $text, $m)) {
            return trim($m[0]);
        }
        if (preg_match('/\(makes?\s+[^)]+\)/i', $text, $m)) {
            return trim($m[0], "() \t");
        }
        return null;
    }

    /**
     * Numeric yields — find the first integer paired with a countable unit.
     * Returns null when no clean number is present.
     */
    private function extractYields(string $methodText, string $bodyText): ?int
    {
        $text = $methodText !== '' ? $methodText : $bodyText;
        if (preg_match('/(?:yields?|makes?|serves)\s+(?:about\s+|approximately\s+|~)?(\d+)\b/i', $text, $m)) {
            return (int) $m[1];
        }
        return null;
    }

    /**
     * Defensive backstop for libation: if SectionExtractor already pulled it,
     * this returns null. If it didn't, we scan the body for `**Libation**: ...`.
     */
    private function extractLibation(string $bodyText): ?string
    {
        if (preg_match('/\*\*libation\*\*\s*:\s*(.+?)(?=\n|$)/iu', $bodyText, $m)) {
            return trim($m[1]);
        }
        return null;
    }
}
