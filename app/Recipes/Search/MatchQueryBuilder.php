<?php

declare(strict_types=1);

namespace App\Recipes\Search;

/**
 * Builds an FTS5 MATCH expression from raw user input.
 *
 * The full FTS5 query grammar is not exposed to end users. Special characters
 * are stripped or neutralized; the only "advanced" syntax v1 supports is
 * double-quoted phrases. Specifically:
 *
 *   - `butter flour`        → matches docs containing both words (implicit AND)
 *   - `"no knead" bread`    → matches docs containing the phrase "no knead" AND "bread"
 *   - `crème café`          → diacritic-stripping is done at index time by FTS5's
 *                              `remove_diacritics 2` tokenizer; user input is passed
 *                              through and the tokenizer normalizes on the way in
 *
 * Each token is wrapped in double quotes in the output to neutralize any
 * remaining special characters (column prefixes, operators, parentheses).
 *
 * Returns `null` when the input has no usable tokens (empty / all-punctuation).
 */
final class MatchQueryBuilder
{
    public function build(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') {
            return null;
        }

        $tokens = [];

        // Pull out balanced phrase quotes first.
        $remaining = $input;
        if (preg_match_all('/"([^"]+)"/u', $input, $matches)) {
            foreach ($matches[1] as $rawPhrase) {
                $clean = trim((string) preg_replace('/[^\p{L}\p{N}\s\-]+/u', ' ', $rawPhrase));
                $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));
                if ($clean !== '') {
                    // Hyphens inside a phrase get treated as separators by FTS5's
                    // tokenizer, so `"no-knead"` works just like `"no knead"`.
                    $tokens[] = '"'.str_replace('-', ' ', $clean).'"';
                }
            }
            $remaining = (string) preg_replace('/"[^"]+"/u', ' ', $input);
        }

        // Remaining text: replace special chars with spaces, then split on whitespace.
        // "butter:flour*" becomes "butter flour" → two tokens, not one collapsed "butterflour".
        $cleanText = (string) preg_replace('/[^\p{L}\p{N}\-]+/u', ' ', $remaining);
        $words = preg_split('/\s+/u', trim($cleanText));
        foreach ((array) $words as $word) {
            $word = trim($word, "- \t");
            if ($word === '') {
                continue;
            }
            // Hyphens become spaces — FTS5 tokenizes the pieces and the
            // surrounding double-quotes keep the order as a phrase.
            $tokens[] = '"'.str_replace('-', ' ', $word).'"';
        }

        return $tokens === [] ? null : implode(' ', $tokens);
    }
}
