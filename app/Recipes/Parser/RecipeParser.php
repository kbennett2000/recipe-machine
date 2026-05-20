<?php

declare(strict_types=1);

namespace App\Recipes\Parser;

use RuntimeException;
use Symfony\Component\Yaml\Exception\ParseException as YamlParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Top-level recipe-file parser.
 *
 * Conforms to docs/recipe-format.md v1.6.
 *
 * Body parsing approach: regex-split on `## ` headers, then `### ` subheaders
 * inside Ingredients. CommonMark AST was considered but rejected — the
 * spec's body grammar is simple enough (4 known section names, bullets and
 * numbered lists inside them) that a markdown AST would be more machinery
 * than we need for v1.
 *
 * Aliases recognized for `## Method` per spec b: `## Instructions`,
 * `## Directions`. Body section names are matched case-insensitively at
 * the start of a `## ` line.
 */
final class RecipeParser
{
    private const METHOD_ALIASES = ['method', 'instructions', 'directions'];

    /**
     * Recognized frontmatter keys. Anything else lands in `extra`.
     */
    private const KNOWN_FRONTMATTER_KEYS = [
        'title', 'category', 'slug', 'servings', 'prep_time', 'cook_time',
        'total_time', 'oven_temp', 'tags', 'libation', 'source', 'difficulty',
        'yields', 'references',
    ];

    public function __construct(
        private readonly IngredientParser $ingredientParser = new IngredientParser,
    ) {}

    public function parseFile(string $path): ParsedRecipe
    {
        if (! is_readable($path)) {
            throw new RuntimeException("Recipe file not readable: {$path}");
        }
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("Failed to read recipe file: {$path}");
        }
        return $this->parseString($contents);
    }

    public function parseString(string $markdown): ParsedRecipe
    {
        $warnings = [];

        [$frontmatterYaml, $body] = $this->splitFrontmatter($markdown);
        $frontmatter = $this->parseFrontmatter($frontmatterYaml);

        $sections = $this->splitBodySections($body);

        $ingredients = isset($sections['ingredients'])
            ? $this->parseIngredients($sections['ingredients'])
            : [];
        if ($ingredients === []) {
            $warnings[] = 'No `## Ingredients` section found, or section was empty.';
        }

        $methodSection = $this->findMethodSection($sections);
        $method = $methodSection !== null ? $this->parseMethodSteps($methodSection) : [];
        if ($method === []) {
            $warnings[] = 'No `## Method` (or alias) section found, or section was empty.';
        }

        $notes = $sections['notes'] ?? null;
        $libationProse = $sections['libation'] ?? null;

        $crossRefs = $this->collectCrossReferences(
            $frontmatter->references,
            [$method, $notes, $libationProse],
        );

        return new ParsedRecipe(
            frontmatter: $frontmatter,
            ingredients: $ingredients,
            method: $method,
            notes: $notes,
            libationProse: $libationProse,
            crossReferences: $crossRefs,
            parseWarnings: $warnings,
        );
    }

    /**
     * @return array{0:string, 1:string} [yamlBlock, body]
     */
    private function splitFrontmatter(string $markdown): array
    {
        // Tolerate a UTF-8 BOM.
        if (str_starts_with($markdown, "\xEF\xBB\xBF")) {
            $markdown = substr($markdown, 3);
        }
        // Frontmatter must start the file with `---` on its own line.
        if (! preg_match('/^---\r?\n(.*?)\r?\n---\r?\n?(.*)$/s', $markdown, $m)) {
            throw new RuntimeException('Recipe file is missing required `---` frontmatter delimiters at the top.');
        }
        return [$m[1], $m[2]];
    }

    private function parseFrontmatter(string $yaml): Frontmatter
    {
        try {
            /** @var array<string,mixed>|null $parsed */
            $parsed = Yaml::parse($yaml);
        } catch (YamlParseException $e) {
            throw new RuntimeException(
                "Malformed YAML frontmatter at line {$e->getParsedLine()}: {$e->getSnippet()}",
                previous: $e,
            );
        }

        if (! is_array($parsed)) {
            throw new RuntimeException('Frontmatter must be a YAML mapping.');
        }

        $title = $this->expectString($parsed, 'title');
        $category = $this->expectString($parsed, 'category');

        $extra = [];
        foreach ($parsed as $key => $value) {
            if (! in_array($key, self::KNOWN_FRONTMATTER_KEYS, true)) {
                $extra[$key] = $value;
            }
        }

        return new Frontmatter(
            title: $title,
            category: $category,
            slug: $this->optionalString($parsed, 'slug'),
            servings: $this->optionalString($parsed, 'servings'),
            prepTime: $this->optionalString($parsed, 'prep_time'),
            cookTime: $this->optionalString($parsed, 'cook_time'),
            totalTime: $this->optionalString($parsed, 'total_time'),
            ovenTemp: $this->optionalString($parsed, 'oven_temp'),
            tags: $this->optionalStringArray($parsed, 'tags'),
            libation: $this->optionalString($parsed, 'libation'),
            source: $this->optionalString($parsed, 'source'),
            difficulty: $this->optionalString($parsed, 'difficulty'),
            yields: $this->optionalInt($parsed, 'yields'),
            references: $this->optionalStringArray($parsed, 'references'),
            extra: $extra,
        );
    }

    private function expectString(array $parsed, string $key): string
    {
        if (! array_key_exists($key, $parsed) || ! is_string($parsed[$key]) || trim($parsed[$key]) === '') {
            throw new RuntimeException("Required frontmatter field `{$key}` is missing or not a non-empty string.");
        }
        return $parsed[$key];
    }

    private function optionalString(array $parsed, string $key): ?string
    {
        if (! array_key_exists($key, $parsed) || $parsed[$key] === null) {
            return null;
        }
        // Coerce ints and floats to strings so YAML literals like `350F` (string)
        // and yields-style numbers don't blow up here.
        return is_scalar($parsed[$key]) ? (string) $parsed[$key] : null;
    }

    private function optionalInt(array $parsed, string $key): ?int
    {
        if (! array_key_exists($key, $parsed) || $parsed[$key] === null) {
            return null;
        }
        if (is_int($parsed[$key])) {
            return $parsed[$key];
        }
        if (is_numeric($parsed[$key])) {
            return (int) $parsed[$key];
        }
        return null;
    }

    /** @return array<string>|null */
    private function optionalStringArray(array $parsed, string $key): ?array
    {
        if (! array_key_exists($key, $parsed) || $parsed[$key] === null) {
            return null;
        }
        if (! is_array($parsed[$key])) {
            return null;
        }
        return array_values(array_map(static fn ($v) => (string) $v, $parsed[$key]));
    }

    /**
     * Split body on `## ` headers. Returns map of lowercased section name → contents.
     *
     * @return array<string,string>
     */
    private function splitBodySections(string $body): array
    {
        $sections = [];
        // Split keeping the headers as delimiters.
        $parts = preg_split('/^(##\s+.+)$/m', $body, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return [];
        }

        $i = 0;
        $n = count($parts);
        // First chunk before any `## ` is preamble — ignored.
        $i = 1;
        while ($i < $n) {
            $header = $parts[$i] ?? '';
            $content = $parts[$i + 1] ?? '';
            $name = strtolower(trim(preg_replace('/^##\s+/', '', $header) ?? ''));
            // Strip any trailing punctuation or weird chars from header.
            $name = trim($name, " \t\n\r:");
            $sections[$name] = trim($content);
            $i += 2;
        }
        return $sections;
    }

    private function findMethodSection(array $sections): ?string
    {
        foreach (self::METHOD_ALIASES as $alias) {
            if (isset($sections[$alias]) && trim($sections[$alias]) !== '') {
                return $sections[$alias];
            }
        }
        return null;
    }

    /**
     * Parse the Ingredients section into an array of ParsedIngredient.
     * Honors `### Sub-group` subheaders by setting `group` on each
     * ingredient parsed beneath them.
     *
     * @return array<ParsedIngredient>
     */
    private function parseIngredients(string $section): array
    {
        $out = [];
        $currentGroup = null;
        $lines = preg_split('/\r?\n/', $section);
        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }
            // Sub-group header `### Name`
            if (preg_match('/^###\s+(.+)$/', $trimmed, $m)) {
                $currentGroup = trim($m[1]);
                continue;
            }
            // Bulleted line — let the ingredient parser handle the marker.
            // We pass the trimmed text; the parser strips the bullet itself.
            if (preg_match('/^[-*+]\s+/', $trimmed) || preg_match('/^\d+[\.)]\s+/', $trimmed)) {
                $out[] = $this->ingredientParser->parseLine($line, $currentGroup);
                continue;
            }
            // Non-bullet, non-header lines are unusual — preserve as raw unparsed.
            $out[] = $this->ingredientParser->parseLine('- '.$trimmed, $currentGroup);
        }

        return $out;
    }

    /**
     * Parse the Method section. Each top-level list item is one step;
     * nested bullets are concatenated as continuation text to the parent.
     *
     * @return array<string>
     */
    private function parseMethodSteps(string $section): array
    {
        $steps = [];
        $current = null;
        $lines = preg_split('/\r?\n/', $section);
        if ($lines === false) {
            return [];
        }

        foreach ($lines as $line) {
            $rstripped = rtrim($line);
            if ($rstripped === '') {
                continue;
            }
            // Top-level item: starts at column 0 with `- ` / `* ` / `1. ` / `1) `
            if (preg_match('/^[-*+]\s+(.*)$/', $rstripped, $m)
                || preg_match('/^\d+[\.)]\s+(.*)$/', $rstripped, $m)) {
                if ($current !== null) {
                    $steps[] = trim($current);
                }
                $current = $m[1];
                continue;
            }
            // Continuation (indented sub-bullet, wrapped line, etc.)
            if ($current !== null) {
                $current .= ' '.trim($rstripped);
                // Collapse leading bullet markers on sub-bullets.
                $current = (string) preg_replace('/\s+(?:[-*+]|\d+[\.)])\s+/', ' ', $current);
            }
        }
        if ($current !== null) {
            $steps[] = trim($current);
        }

        return $steps;
    }

    /**
     * Union of frontmatter `references` and inline `[[bracket]]` mentions found
     * in any of the passed string-or-array sources. Deduped, slug-cased.
     *
     * @param  array<string>|null  $frontmatterRefs
     * @param  array<string|array<string>|null>  $sources
     * @return array<string>
     */
    private function collectCrossReferences(?array $frontmatterRefs, array $sources): array
    {
        $refs = [];
        foreach ((array) $frontmatterRefs as $r) {
            $refs[] = $this->normalizeRef((string) $r);
        }
        foreach ($sources as $src) {
            $text = is_array($src) ? implode("\n", $src) : (string) $src;
            if ($text === '') {
                continue;
            }
            if (preg_match_all('/\[\[(.+?)\]\]/u', $text, $m)) {
                foreach ($m[1] as $captured) {
                    $refs[] = $this->normalizeRef($captured);
                }
            }
        }
        $refs = array_values(array_unique(array_filter($refs, static fn (string $r) => $r !== '')));
        return $refs;
    }

    private function normalizeRef(string $ref): string
    {
        $ref = trim($ref);
        // If it already looks like a slug, keep it. Otherwise slugify the title.
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $ref)) {
            return $ref;
        }
        $lower = mb_strtolower($ref);
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $lower);
        return trim($slug, '-');
    }
}
