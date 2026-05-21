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
