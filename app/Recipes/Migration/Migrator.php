<?php

declare(strict_types=1);

namespace App\Recipes\Migration;

use App\Recipes\Parser\ParsedIngredient;
use App\Recipes\Parser\RecipeParser;
use RuntimeException;

/**
 * Orchestrates the source-codex → per-recipe-file migration.
 *
 * Flow:
 *   1. SourceParser splits the big file into SourceRecipe sections.
 *   2. For each section, SectionExtractor pulls out ingredients/method/notes/libation.
 *   3. FrontmatterExtractor mines oven_temp, cook_time, servings, yields.
 *   4. Hardcoded special-case handlers apply per-slug rules (cross-references,
 *      starter split, etc.) listed in the Phase 2B brief.
 *   5. RecipeRenderer produces the final markdown.
 *   6. (Unless --dry-run) the file is written.
 *   7. RecipeParser parses the output back; ingredient parse stats and
 *      cross-reference detection feed the MigrationResult.
 */
final class Migrator
{
    /**
     * Special-case cross-reference additions keyed by output slug.
     * The migration adds these to the frontmatter `references` array
     * regardless of what the source content said.
     *
     * @var array<string, array<string>>
     */
    private const CROSS_REF_ADDITIONS = [
        'pretzel-bread-loaves-laugenbrot'      => ['big-soft-pretzels'],
        'apple-pie'                            => ['pie-crust'],
        'french-silk-pie'                      => ['pie-crust'],
        'royal-groktanstelvanian-sourdough-boule' => ['sourdough-starter'],
    ];

    public function __construct(
        private readonly SourceParser $sourceParser = new SourceParser,
        private readonly SectionExtractor $sectionExtractor = new SectionExtractor,
        private readonly FrontmatterExtractor $frontmatterExtractor = new FrontmatterExtractor,
        private readonly RecipeRenderer $renderer = new RecipeRenderer,
        private readonly RecipeParser $recipeParser = new RecipeParser,
    ) {}

    /**
     * Run the migration.
     *
     * @param  string  $sourcePath  Path to source markdown file.
     * @param  string  $category  Category slug, or "auto" to infer.
     * @param  bool  $dryRun  When true, no files are written.
     * @param  string  $outputRoot  Where recipes/<category>/ lives.
     * @return array{
     *   sourcePath: string,
     *   inferredCategory: ?string,
     *   results: array<MigrationResult>,
     *   unparsedCorpus: array<string>,
     * }
     */
    public function migrate(string $sourcePath, string $category, bool $dryRun, string $outputRoot): array
    {
        if (! is_readable($sourcePath)) {
            throw new RuntimeException("Source file not readable: {$sourcePath}");
        }

        $rawSource = (string) file_get_contents($sourcePath);
        $parsed = $this->sourceParser->parse($rawSource);
        $codexTitle = $parsed['title'];
        $recipes = $parsed['recipes'];
        $mode = $parsed['mode'];

        $inferredCategory = $codexTitle !== null
            ? $this->sourceParser->inferCategory($codexTitle)
            : null;

        // In hierarchical mode each recipe carries its own category from the
        // `## Category` divider, so `--category=auto` is always fine. In flat
        // mode we need an inferable H1 — otherwise the user must pass --category.
        $defaultCategory = null;
        if ($mode === 'hierarchical') {
            $defaultCategory = $category !== 'auto' ? $category : null;
        } else {
            $defaultCategory = $category === 'auto'
                ? ($inferredCategory ?? throw new RuntimeException(
                    "Could not infer category from H1 title '{$codexTitle}'. Pass --category=<slug> explicitly."
                ))
                : $category;
        }

        $results = [];
        $unparsedCorpus = [];

        foreach ($recipes as $source) {
            $slug = $this->sourceParser->slugify($source->title);

            // Resolve the per-recipe category. Hierarchical mode → use the
            // category attached to the SourceRecipe; flat mode → the global default.
            $category = $source->category ?? $defaultCategory
                ?? throw new RuntimeException(
                    "No category resolved for recipe '{$source->title}'. Pass --category=<slug> explicitly."
                );

            // Special-case: sourdough-boule may contain an inline starter section.
            // (In the real bread codex it's a sibling H2, so this rarely fires —
            // but the synthetic test-2B.1 fixture exercises the nested case.)
            $starterResult = null;
            $bodyForParent = $source->body;
            if ($slug === 'royal-groktanstelvanian-sourdough-boule') {
                [$bodyForParent, $starterBody] = $this->splitOutSourdoughStarter($source->body);
                if ($starterBody !== null) {
                    $starterSource = new SourceRecipe(
                        title: 'Sourdough Starter',
                        body: $starterBody,
                        sourceLine: $source->sourceLine,
                        category: $category,
                    );
                    $starterResult = $this->migrateOne($starterSource, $category, $dryRun, $outputRoot);
                }
            }

            $maybeAdjustedSource = $bodyForParent === $source->body
                ? $source
                : new SourceRecipe($source->title, $bodyForParent, $source->sourceLine, $source->category);

            $parentResult = $this->migrateOne($maybeAdjustedSource, $category, $dryRun, $outputRoot);
            $results[] = $parentResult;
            $unparsedCorpus = array_merge($unparsedCorpus, $parentResult->unparsedLines);

            if ($starterResult !== null) {
                $results[] = $starterResult;
                $unparsedCorpus = array_merge($unparsedCorpus, $starterResult->unparsedLines);
            }
        }

        return [
            'sourcePath'       => $sourcePath,
            'inferredCategory' => $inferredCategory,
            'results'          => $results,
            'unparsedCorpus'   => $unparsedCorpus,
        ];
    }

    /**
     * Migrate a single SourceRecipe to disk (or just simulate, if --dry-run).
     */
    private function migrateOne(SourceRecipe $source, string $category, bool $dryRun, string $outputRoot): MigrationResult
    {
        $slug = $this->sourceParser->slugify($source->title);
        $sections = $this->sectionExtractor->extract($source->body);
        $bodyLines = explode("\n", $source->body);

        // Special case: the sourdough-starter source body is instructional prose
        // structured as Day 1 / Day 2 / etc. bullets — not a conventional recipe.
        // The Phase 2B brief specifies routing it to `## Notes`. Override here so
        // the bullets don't get misclassified as ingredients.
        if ($slug === 'sourdough-starter') {
            $sections['ingredients'] = [];
            $sections['method'] = [];
            $sections['notes'] = explode("\n", trim($source->body));
        }

        $extracted = $this->frontmatterExtractor->extract($sections['method'], $bodyLines);

        $libation = $sections['libation'] ?? $extracted['libation'];

        // Merge bonus content into notes for the output.
        $notes = $sections['notes'];
        if ($sections['bonus'] !== []) {
            if ($notes !== []) {
                $notes = array_merge($notes, [''], $sections['bonus']);
            } else {
                $notes = $sections['bonus'];
            }
        }

        $references = self::CROSS_REF_ADDITIONS[$slug] ?? null;

        $frontmatter = array_filter([
            'title'       => $source->title,
            'category'    => $category,
            'slug'        => $slug,
            'servings'    => $extracted['servings'],
            'yields'      => $extracted['yields'],
            'oven_temp'   => $extracted['oven_temp'],
            'cook_time'   => $extracted['cook_time'],
            'tags'        => [],          // always emitted; hand-curated later
            'libation'    => $libation,
            'references'  => $references,
        ], fn ($v) => $v !== null);

        $rendered = $this->renderer->render(
            frontmatter: $frontmatter,
            ingredientLines: $sections['ingredients'],
            methodInput: $sections['method'],
            notesLines: $notes,
        );

        $targetDir = $this->categoryDirectoryName($category);
        $targetPath = rtrim($outputRoot, '/').'/'.$targetDir.'/'.$slug.'.md';

        $wrote = false;
        if (! $dryRun) {
            $dir = dirname($targetPath);
            if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
                throw new RuntimeException("Could not create output directory: {$dir}");
            }
            file_put_contents($targetPath, $rendered['markdown']);
            $wrote = true;
        }

        // Round-trip validation: parse what we'd write (or did write).
        $unparsedLines = [];
        $ingredientCount = 0;
        $parsedCount = 0;
        $methodStepCount = 0;
        $crossRefs = [];

        try {
            $reparsed = $this->recipeParser->parseString($rendered['markdown']);
            $ingredientCount = count($reparsed->ingredients);
            foreach ($reparsed->ingredients as $ing) {
                /** @var ParsedIngredient $ing */
                if ($ing->parsed) {
                    $parsedCount++;
                } else {
                    $unparsedLines[] = $ing->raw;
                }
            }
            $methodStepCount = count($reparsed->method);
            $crossRefs = $reparsed->crossReferences;
        } catch (\Throwable $e) {
            $rendered['warnings'][] = 'Round-trip parse FAILED: '.$e->getMessage();
        }

        $populated = array_keys($frontmatter);
        $missing = array_values(array_diff(
            ['title', 'category', 'slug', 'servings', 'yields',
             'prep_time', 'cook_time', 'total_time', 'oven_temp',
             'difficulty', 'tags', 'libation', 'source', 'references'],
            $populated,
        ));

        return new MigrationResult(
            sourceTitle: $source->title,
            slug: $slug,
            category: $category,
            targetPath: $targetPath,
            wrote: $wrote,
            ingredientCount: $ingredientCount,
            parsedCount: $parsedCount,
            unparsedLines: $unparsedLines,
            methodStepCount: $methodStepCount,
            frontmatterPopulated: $populated,
            frontmatterMissing: $missing,
            crossReferences: $crossRefs,
            warnings: $rendered['warnings'],
        );
    }

    /**
     * The sourdough recipe contains a `### Bonus: How to Create Thy Royal
     * Starter` (or similar) inline. Split that out so it can be migrated
     * as its own file.
     *
     * @return array{0:string, 1:?string}  [parentBodyWithStarterRemoved, starterBody]
     */
    private function splitOutSourdoughStarter(string $body): array
    {
        $lines = explode("\n", $body);
        $starterStart = null;
        $starterEnd = null;
        foreach ($lines as $i => $line) {
            if (preg_match('/^#{2,6}\s+.*starter/i', $line)) {
                $starterStart = $i;
                break;
            }
        }
        if ($starterStart === null) {
            return [$body, null];
        }
        // Starter section runs until the next same-or-shallower header, or EOF.
        $starterHeaderLevel = strlen($this->headerHashes($lines[$starterStart]));
        for ($j = $starterStart + 1; $j < count($lines); $j++) {
            if (preg_match('/^(#{2,6})\s+/', $lines[$j], $m) && strlen($m[1]) <= $starterHeaderLevel) {
                $starterEnd = $j;
                break;
            }
        }
        $starterEnd ??= count($lines);

        $starterBody = implode("\n", array_slice($lines, $starterStart + 1, $starterEnd - $starterStart - 1));
        $parentBody = implode("\n", array_merge(
            array_slice($lines, 0, $starterStart),
            array_slice($lines, $starterEnd),
        ));
        return [$parentBody, trim($starterBody)];
    }

    private function headerHashes(string $line): string
    {
        return preg_match('/^(#{2,6})\s+/', $line, $m) ? $m[1] : '';
    }

    /**
     * Map category slug (singular per spec frontmatter) to the directory name
     * (plural per Phase 0). The recommended categories are: breads, sauces,
     * soups, entrees, desserts, seafood — all already plural; the singular vs
     * plural distinction now collapsed in Phase 1.5, so this is a no-op for
     * recommended categories but provides a single chokepoint if it changes.
     */
    private function categoryDirectoryName(string $category): string
    {
        return $category;
    }
}
