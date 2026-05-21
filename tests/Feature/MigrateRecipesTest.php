<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Recipes\Migration\Migrator;
use App\Recipes\Migration\SourceParser;
use PHPUnit\Framework\TestCase;

/**
 * Feature tests for the recipe migration tool.
 *
 * These exercise the Migrator class directly with synthetic source
 * input so they don't depend on Laravel's full kernel boot. The
 * Artisan command itself is a thin wrapper over Migrator and gets
 * covered transitively.
 */
final class MigrateRecipesTest extends TestCase
{
    private string $tmpDir;
    private string $outputRoot;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/recipe-machine-migrate-'.uniqid('', true);
        $this->outputRoot = $this->tmpDir.'/recipes';
        mkdir($this->tmpDir, 0775, true);
        mkdir($this->outputRoot, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    public function test_dry_run_splits_two_recipes_without_writing_files(): void
    {
        $source = <<<'MD'
            # The Tiny Codex of Two Breads

            ## 1. Honey Oat Bread

            - 3 cups flour
            - 1 1/2 tsp salt

            **Method**: Mix and knead. Bake 35-40 minutes at 350F.

            **Libation**: Mead.

            ## 2. Sourdough Loaf

            - 4 cups bread flour
            - 1 tsp salt

            **Method**: Fold the dough. Bake at 450F for 30 minutes.
            MD;
        $sourcePath = $this->tmpDir.'/two-breads.md';
        file_put_contents($sourcePath, $source);

        $migrator = new Migrator;
        $outcome = $migrator->migrate($sourcePath, 'breads', dryRun: true, outputRoot: $this->outputRoot);

        $this->assertCount(2, $outcome['results']);
        $this->assertSame('Honey Oat Bread', $outcome['results'][0]->sourceTitle);
        $this->assertSame('honey-oat-bread', $outcome['results'][0]->slug);
        $this->assertSame('Sourdough Loaf', $outcome['results'][1]->sourceTitle);
        $this->assertSame('sourdough-loaf', $outcome['results'][1]->slug);

        // No files should exist on disk after a dry-run.
        $this->assertFileDoesNotExist($this->outputRoot.'/breads/honey-oat-bread.md');
        $this->assertFileDoesNotExist($this->outputRoot.'/breads/sourdough-loaf.md');

        foreach ($outcome['results'] as $r) {
            $this->assertFalse($r->wrote, "Recipe `{$r->slug}` should NOT have been written in dry-run mode");
        }
    }

    public function test_category_auto_infers_from_h1_title(): void
    {
        $source = <<<'MD'
            # The Royal Codex of Breads

            ## Honey Oat Bread

            - 3 cups flour

            **Method**: Mix and knead. Bake.
            MD;
        $sourcePath = $this->tmpDir.'/codex.md';
        file_put_contents($sourcePath, $source);

        $migrator = new Migrator;
        $outcome = $migrator->migrate($sourcePath, 'auto', dryRun: true, outputRoot: $this->outputRoot);

        $this->assertSame('breads', $outcome['inferredCategory']);
        $this->assertSame('breads', $outcome['results'][0]->category);
        $this->assertStringContainsString('/breads/honey-oat-bread.md', $outcome['results'][0]->targetPath);
    }

    public function test_numeric_prefix_is_stripped_from_slug(): void
    {
        $source = <<<'MD'
            # Test Codex of Breads

            ## 4. Honey Oat Bread

            - 3 cups flour

            **Method**: Mix.
            MD;
        $sourcePath = $this->tmpDir.'/numbered.md';
        file_put_contents($sourcePath, $source);

        $migrator = new Migrator;
        $outcome = $migrator->migrate($sourcePath, 'breads', dryRun: true, outputRoot: $this->outputRoot);

        $this->assertSame('Honey Oat Bread', $outcome['results'][0]->sourceTitle,
            'Numeric "4." prefix should be stripped from the title.');
        $this->assertSame('honey-oat-bread', $outcome['results'][0]->slug,
            'Slug must not include the leading numeric prefix.');
        $this->assertStringNotContainsString('4-', $outcome['results'][0]->slug);
    }

    public function test_round_trip_parse_succeeds_for_minimal_recipe(): void
    {
        $source = <<<'MD'
            # Test Codex of Breads

            ## Honey Oat Bread

            - 3 cups flour
            - 1 1/2 tsp salt
            - 2 1/4 tsp instant yeast

            **Method**: Mix the dough. Knead until smooth. Let rise 1 hour. Bake at 350F for 35-40 minutes.

            **Libation**: Mead — honey loves honey.
            MD;
        $sourcePath = $this->tmpDir.'/round-trip.md';
        file_put_contents($sourcePath, $source);

        $migrator = new Migrator;
        $outcome = $migrator->migrate($sourcePath, 'breads', dryRun: false, outputRoot: $this->outputRoot);

        $written = $this->outputRoot.'/breads/honey-oat-bread.md';
        $this->assertFileExists($written, 'Migrator should have written the recipe file.');

        $r = $outcome['results'][0];
        $this->assertTrue($r->wrote);
        $this->assertSame(3, $r->ingredientCount);
        $this->assertSame(3, $r->parsedCount, 'All three ingredients should round-trip-parse cleanly.');
        $this->assertSame(0, count($r->unparsedLines));
        $this->assertGreaterThan(0, $r->methodStepCount, 'Method should split into at least one step.');
        $this->assertContains('oven_temp', $r->frontmatterPopulated, 'Oven temp should be extracted from the method prose.');
        $this->assertContains('libation', $r->frontmatterPopulated, 'Libation should be lifted out of the body.');
    }

    public function test_table_of_contents_section_is_skipped(): void
    {
        $source = <<<'MD'
            # The Codex of Breads

            ## Table of Contents

            - Honey Oat Bread
            - Sourdough Loaf

            ## Honey Oat Bread

            - 3 cups flour

            **Method**: Knead and bake.
            MD;
        $sourcePath = $this->tmpDir.'/with-toc.md';
        file_put_contents($sourcePath, $source);

        $migrator = new Migrator;
        $outcome = $migrator->migrate($sourcePath, 'breads', dryRun: true, outputRoot: $this->outputRoot);

        $this->assertCount(1, $outcome['results'], 'Table of Contents section should be skipped.');
        $this->assertSame('Honey Oat Bread', $outcome['results'][0]->sourceTitle);
    }

    public function test_sourdough_starter_split_extracts_nested_subsubsection(): void
    {
        // Mimics the predicted structure of the real bread codex:
        //   ## Royal Groktanstelvanian Sourdough Boule
        //   (parent ingredients / method / libation)
        //   ### Bonus: The Sacred Sourdough Starter      <-- level 3, first "starter" header
        //     (intro prose about the starter)
        //     #### How to Create Thy Royal Starter        <-- level 4, sub-sub-section
        //       (ingredients + method for growing the starter)
        $source = <<<'MD'
            # The Official Royal Codex of Kick-Ass Breads

            ## Royal Groktanstelvanian Sourdough Boule

            - 4 cups bread flour
            - 1 1/2 tsp salt
            - 1 cup active sourdough starter
            - 1 1/2 cups warm water

            **Method**: Mix all ingredients in a large bowl. Knead briefly until shaggy. Bulk ferment overnight at room temperature. Shape and proof 4 hours. Bake in a Dutch oven at 500F for 45 minutes total.

            **Libation**: Dry farmhouse ale.

            ### Bonus: The Sacred Sourdough Starter

            A living culture, fed and tended over weeks. The boule above needs an active starter — here's how to grow one from scratch.

            #### How to Create Thy Royal Starter

            - 1/2 cup whole wheat flour
            - 1/2 cup filtered water

            **Method**: Combine flour and water in a clean glass jar. Cover loosely with a cloth. Feed daily for 7 days by doubling the starter with equal parts flour and water and discarding half.

            **Libation**: Iced coffee, because you'll be doing dishes.
            MD;
        $sourcePath = $this->tmpDir.'/sourdough-codex.md';
        file_put_contents($sourcePath, $source);

        $migrator = new Migrator;

        // First pass: --dry-run reports two recipes.
        $dryOutcome = $migrator->migrate($sourcePath, 'breads', dryRun: true, outputRoot: $this->outputRoot);
        $this->assertCount(2, $dryOutcome['results'],
            '--dry-run should report TWO recipes (parent + extracted starter), not one.');

        // Second pass: actually write files so we can inspect rendered bodies.
        $outcome = $migrator->migrate($sourcePath, 'breads', dryRun: false, outputRoot: $this->outputRoot);
        $this->assertCount(2, $outcome['results']);

        $parent = $outcome['results'][0];
        $starter = $outcome['results'][1];

        $this->assertSame('royal-groktanstelvanian-sourdough-boule', $parent->slug,
            'Parent slug must match the title slugified — numeric prefix stripping, no leading "1-" etc.');
        $this->assertSame('sourdough-starter', $starter->slug,
            'Starter slug must be "sourdough-starter" (the synthetic title the Migrator gives the split recipe).');

        $parentFile = (string) file_get_contents($parent->targetPath);
        $starterFile = (string) file_get_contents($starter->targetPath);

        // Starter file must include the contents of the deeply-nested
        // #### How to Create sub-sub-section: at minimum the ingredient
        // bullets and the method-prose seed phrase.
        $this->assertStringContainsString('whole wheat flour', $starterFile,
            'Starter body should include the bullets from the #### How to Create section.');
        $this->assertStringContainsString('filtered water', $starterFile);
        $this->assertStringContainsString('Combine flour and water', $starterFile,
            'Starter body should include the method-prose from the #### How to Create section.');

        // Phase 2B.2 fix: leading intro prose ("A living culture, fed and tended...")
        // used to be silently dropped by extractLeadingBullets. It now lands in ## Notes.
        $this->assertStringContainsString('## Notes', $starterFile,
            'Starter should have a ## Notes section (intro prose preserved as notes).');
        $this->assertStringContainsString('A living culture', $starterFile,
            'Intro prose must land in the starter\'s Notes, not be dropped.');

        // Parent frontmatter must include references: [sourdough-starter].
        // We check via the round-trip cross_references list rather than parsing
        // YAML by hand — that's exactly what the spec/parser contract guarantees.
        $this->assertContains('sourdough-starter', $parent->crossReferences,
            'Parent should have references: [sourdough-starter] in frontmatter.');

        // Parent body must NOT contain any starter content. If splitOutSourdoughStarter
        // undershoots, the bonus block leaks into the parent's Ingredients/Method/Notes.
        $this->assertStringNotContainsString('whole wheat flour', $parentFile,
            'Parent body must not contain starter ingredients — the split undershot.');
        $this->assertStringNotContainsString('How to Create Thy Royal Starter', $parentFile,
            'Parent body must not contain the starter sub-sub-header — the split undershot.');
        $this->assertStringNotContainsString('Sacred Sourdough Starter', $parentFile,
            'Parent body must not contain the bonus-section header — the split undershot.');
        $this->assertStringNotContainsString('Feed daily for 7 days', $parentFile,
            'Parent body must not contain the starter method prose — the split undershot.');
    }

    public function test_starter_as_ingredient_does_not_trigger_split(): void
    {
        // False-positive guard: a recipe with "1 cup active sourdough starter"
        // in its ingredient list (or anywhere else) must NOT be treated as the
        // royal-groktanstelvanian-sourdough-boule and split. Only the exact
        // slug match triggers splitOutSourdoughStarter.
        $source = <<<'MD'
            # The Codex of Breads

            ## Casual Weekday Boule

            - 3 cups bread flour
            - 1 cup active sourdough starter
            - 1 tsp salt
            - 1 cup water

            **Method**: Mix until shaggy. Knead briefly. Bulk ferment 4 hours. Shape and proof 2 hours. Bake at 425F for 30 minutes.

            **Libation**: Whatever's open.
            MD;
        $sourcePath = $this->tmpDir.'/casual-boule.md';
        file_put_contents($sourcePath, $source);

        $migrator = new Migrator;
        $outcome = $migrator->migrate($sourcePath, 'breads', dryRun: true, outputRoot: $this->outputRoot);

        $this->assertCount(1, $outcome['results'],
            'A recipe that merely mentions "sourdough starter" in ingredients must not be split.');
        $this->assertSame('casual-weekday-boule', $outcome['results'][0]->slug);
        // No phantom sourdough-starter.md should be queued for writing.
        foreach ($outcome['results'] as $r) {
            $this->assertNotSame('sourdough-starter', $r->slug);
        }
        $this->assertFileDoesNotExist($this->outputRoot.'/breads/sourdough-starter.md');
    }

    public function test_non_starter_bonus_section_lands_in_notes(): void
    {
        // A `### Bonus: <label>` sub-section that is NOT a starter should:
        //   - keep the parent recipe single (no split into two files)
        //   - have its content routed into the parent's ## Notes section
        //   - NOT pollute Ingredients or Method
        $source = <<<'MD'
            # The Codex of Breads

            ## Equipment-Heavy Bread

            - 3 cups bread flour
            - 1 tsp salt
            - 1 cup warm water
            - 2 tsp instant yeast

            **Method**: Mix and knead until smooth. Rise 90 minutes. Shape into a loaf. Final proof 45 minutes. Bake at 425F for 30 minutes.

            ### Bonus: Equipment Notes

            Use a banneton if you have one — the rattan texture leaves nice flour marks. A heavy enameled Dutch oven works best for the bake; preheat it for 30 minutes before loading.
            MD;
        $sourcePath = $this->tmpDir.'/equipment-bonus.md';
        file_put_contents($sourcePath, $source);

        $migrator = new Migrator;
        $outcome = $migrator->migrate($sourcePath, 'breads', dryRun: false, outputRoot: $this->outputRoot);

        $this->assertCount(1, $outcome['results'],
            'Non-starter bonus sections must NOT cause a recipe split.');

        $file = (string) file_get_contents($outcome['results'][0]->targetPath);

        // Bonus content lands in Notes.
        $this->assertStringContainsString('## Notes', $file);
        $this->assertStringContainsString('banneton', $file);
        $this->assertStringContainsString('Dutch oven', $file);

        // The 'banneton' text must appear AFTER the `## Notes` header — i.e. inside it.
        $notesPos = strpos($file, '## Notes');
        $bannetonPos = strpos($file, 'banneton');
        $this->assertNotFalse($notesPos);
        $this->assertNotFalse($bannetonPos);
        $this->assertGreaterThan($notesPos, $bannetonPos,
            'Bonus content must appear under the ## Notes header, not above it.');

        // No phantom equipment-notes.md.
        $this->assertFileDoesNotExist($this->outputRoot.'/breads/equipment-notes.md');
    }

    public function test_slug_strips_special_characters(): void
    {
        $parser = new SourceParser;
        $this->assertSame('honey-oat-bread', $parser->slugify('Honey Oat Bread'));
        $this->assertSame('50-50-white-whole-wheat-bread', $parser->slugify('50/50 White & Whole Wheat Bread'));
        $this->assertSame('pretzel-bread-loaves-laugenbrot', $parser->slugify('Pretzel Bread Loaves (Laugenbrot)'));
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.'/'.$entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
