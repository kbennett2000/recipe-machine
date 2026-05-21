<?php

declare(strict_types=1);

namespace App\Recipes\Render;

/**
 * Scans a chunk of markdown for bare recipe titles in prose and wraps the
 * first occurrence of each into a `[Title](/recipes/slug)` markdown link.
 *
 * AutoLinker runs AFTER the `[[bracket]]` resolver in the notes-render
 * pipeline, so by the time it sees the text, explicit `[[refs]]` have
 * already been converted to either `[Title](url)` markdown links or
 * `**Text**` bold blocks. AutoLinker treats both of those as
 * "do-not-touch" regions.
 *
 * Rules:
 *   - Skip anything inside `[[..]]`, `[..](..)`, `` `..` ``, or `<..>`.
 *   - Titles shorter than 6 chars are NOT auto-linked. Short titles
 *     ("Pasta", "Bread") would carpet-bomb every step that mentions
 *     the noun — the user must use `[[brackets]]` for those.
 *   - First occurrence per title wins; repeated mentions stay plain.
 *   - Self-references (a recipe's own slug) are never linked.
 *   - Match is case-insensitive; link text uses the title's canonical
 *     case from the index.
 *   - Longer titles win over shorter when they share a prefix
 *     ("Apple Pie" beats "Pie") via length-descending alternation.
 *
 * The class is stateless across calls — `link()` builds its own state
 * for the input string and discards it on return.
 */
final class AutoLinker
{
    /** Titles shorter than this don't auto-link. */
    private const MIN_TITLE_LEN = 6;

    /**
     * @param  string                $markdown    Raw markdown (post-bracket-resolve, pre-CommonMark).
     * @param  array<string,string>  $recipeIndex Map of slug => title.
     * @param  string                $currentSlug Slug of the recipe whose notes we're rendering — never link to self.
     */
    public function link(string $markdown, array $recipeIndex, string $currentSlug): string
    {
        $eligible = [];
        foreach ($recipeIndex as $slug => $title) {
            if ($slug === $currentSlug) {
                continue;
            }
            if (mb_strlen($title) < self::MIN_TITLE_LEN) {
                continue;
            }
            $eligible[$slug] = $title;
        }
        if ($eligible === []) {
            return $markdown;
        }

        // Sort by title length DESC so the alternation matches the longest
        // candidate first ("Apple Pie" before "Pie", "Pretzel Bread Loaves"
        // before "Pretzel Bread"). PCRE alternation is leftmost-first.
        uasort($eligible, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        // Reverse lookup for the callback: lowercase title => {slug, title}.
        $titleToEntry = [];
        $titleAlts = [];
        foreach ($eligible as $slug => $title) {
            $titleToEntry[mb_strtolower($title)] = ['slug' => $slug, 'title' => $title];
            $titleAlts[] = preg_quote($title, '/');
        }
        $titleAlternation = implode('|', $titleAlts);

        // Skip regions in order: explicit bracket refs (defense in depth —
        // the resolver should have eaten these already), markdown links,
        // inline code spans, and HTML tags. Each branch is non-overlapping.
        $skipPattern = '\\[\\[[^\\]]+\\]\\]'         // [[bracket ref]]
            .'|\\[[^\\]]+\\]\\([^\\)]+\\)'           // [text](url)
            .'|`[^`]+`'                              // `code span`
            .'|<[^>]+>';                             // <html tag>

        // Stricter than \b: also forbid adjacent hyphens. Plain \b would
        // happily match "cornbread" inside "cornbread-related" because PCRE
        // treats `-` as a non-word char and so the boundary fires. We want
        // the title to be a standalone word, not a compound-word fragment.
        $master = '/('.$skipPattern.')|(?<![\\w-])('.$titleAlternation.')(?![\\w-])/iu';

        $linked = [];

        return (string) preg_replace_callback($master, function (array $m) use (&$linked, $titleToEntry): string {
            // Branch 1: skip region — return verbatim.
            if (isset($m[1]) && $m[1] !== '') {
                return $m[0];
            }
            // Branch 2: candidate title match.
            if (! isset($m[2]) || $m[2] === '') {
                return $m[0];
            }
            $key = mb_strtolower($m[2]);
            if (! isset($titleToEntry[$key])) {
                return $m[0];
            }
            $entry = $titleToEntry[$key];
            if (isset($linked[$entry['slug']])) {
                return $m[0];
            }
            $linked[$entry['slug']] = true;
            return '['.$entry['title'].'](/recipes/'.$entry['slug'].')';
        }, $markdown);
    }
}
