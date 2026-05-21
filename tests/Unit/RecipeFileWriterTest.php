<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Recipes\Files\RecipeFileWriter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Phase 11C — RecipeFileWriter unit tests.
 *
 * Every test runs against a temporary fixture root under sys_get_temp_dir,
 * so the real recipes/ directory is never touched. The fixture root is
 * created fresh in setUp() and torn down in tearDown() — no shared state
 * between tests.
 */
final class RecipeFileWriterTest extends TestCase
{
    private string $root;

    private RecipeFileWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/recipe-file-writer-test-'.uniqid('', true);
        mkdir($this->root, 0777, true);
        mkdir($this->root.'/breads', 0777, true);
        mkdir($this->root.'/desserts', 0777, true);
        $this->writer = new RecipeFileWriter($this->root);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->rmrf($this->root);
        }
        parent::tearDown();
    }

    // === Successful writes ===

    public function test_writing_new_recipe_creates_file(): void
    {
        $md = $this->sampleMarkdown('My Bread');
        $result = $this->writer->write('my-bread', 'breads', $md);

        $this->assertSame('created', $result->status);
        $this->assertFileExists($result->path);
        $this->assertSame($this->root.'/breads/my-bread.md', $result->path);
    }

    public function test_writing_existing_slug_updates_file(): void
    {
        $this->writer->write('my-bread', 'breads', $this->sampleMarkdown('Original'));
        $result = $this->writer->write('my-bread', 'breads', $this->sampleMarkdown('Revised'));

        $this->assertSame('updated', $result->status);
        $this->assertStringContainsString('Revised', file_get_contents($result->path));
    }

    public function test_written_content_matches_input_exactly(): void
    {
        $md = "---\ntitle: Exact\ncategory: breads\nslug: exact\n---\n\n## Ingredients\n\n- 1 cup flour\n";
        $result = $this->writer->write('exact', 'breads', $md);

        $this->assertSame($md, file_get_contents($result->path), 'No BOM, no trailing whitespace munging, no extra newlines');
    }

    // === Slug validation ===

    public function test_invalid_slug_with_space_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid slug/');
        $this->writer->write('Foo Bar', 'breads', $this->sampleMarkdown('x'));
    }

    public function test_invalid_slug_with_path_traversal_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid slug/');
        $this->writer->write('../etc/passwd', 'breads', $this->sampleMarkdown('x'));
    }

    public function test_invalid_slug_leading_hyphen_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid slug/');
        $this->writer->write('-leading-hyphen', 'breads', $this->sampleMarkdown('x'));
    }

    public function test_invalid_slug_uppercase_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid slug/');
        $this->writer->write('CAPS', 'breads', $this->sampleMarkdown('x'));
    }

    // === Category validation ===

    public function test_invalid_category_uppercase_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid category/');
        $this->writer->write('my-bread', 'Sauces', $this->sampleMarkdown('x'));
    }

    public function test_invalid_category_with_slash_throws(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Invalid category/');
        $this->writer->write('my-bread', 'sauces/sub', $this->sampleMarkdown('x'));
    }

    public function test_nonexistent_category_directory_throws_with_actionable_message(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/no directory at recipes\/soups\/.*Create the directory/');
        $this->writer->write('my-soup', 'soups', $this->sampleMarkdown('Soup'));
    }

    // === Slug uniqueness across categories ===

    public function test_writing_existing_slug_in_different_category_throws(): void
    {
        $this->writer->write('shared', 'breads', $this->sampleMarkdown('Shared in breads'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already exists at recipes\/breads\/shared.md/');
        $this->writer->write('shared', 'desserts', $this->sampleMarkdown('Shared in desserts'));
    }

    // === Existence check ===

    public function test_exists_returns_false_for_nonexistent_slug(): void
    {
        $this->assertFalse($this->writer->exists('nope'));
    }

    public function test_exists_returns_true_after_write(): void
    {
        $this->writer->write('present', 'breads', $this->sampleMarkdown('x'));
        $this->assertTrue($this->writer->exists('present'));
    }

    // === Delete ===

    public function test_delete_removes_existing_file(): void
    {
        $result = $this->writer->write('to-delete', 'breads', $this->sampleMarkdown('x'));
        $this->assertFileExists($result->path);

        $delete = $this->writer->delete('to-delete');
        $this->assertSame('deleted', $delete->status);
        $this->assertSame($result->path, $delete->path);
        $this->assertFileDoesNotExist($result->path);
    }

    public function test_delete_returns_not_found_for_missing_file(): void
    {
        $delete = $this->writer->delete('never-existed');
        $this->assertSame('not_found', $delete->status);
        $this->assertNull($delete->path);
    }

    // === Path safety ===

    public function test_resolve_path_with_category_returns_target(): void
    {
        $path = $this->writer->resolvePath('hypothetical', 'breads');
        $this->assertSame($this->root.'/breads/hypothetical.md', $path);
    }

    public function test_resolve_path_without_category_locates_existing(): void
    {
        $this->writer->write('locate-me', 'desserts', $this->sampleMarkdown('x'));
        $path = $this->writer->resolvePath('locate-me');
        $this->assertSame($this->root.'/desserts/locate-me.md', $path);
    }

    public function test_constructor_rejects_nonexistent_root(): void
    {
        $this->expectException(RuntimeException::class);
        new RecipeFileWriter($this->root.'/does-not-exist');
    }

    // === Atomicity ===

    public function test_temp_file_is_cleaned_up_after_successful_write(): void
    {
        // After a successful write, no .tmp.* files should remain in the
        // category directory.
        $this->writer->write('clean', 'breads', $this->sampleMarkdown('x'));
        $leftovers = array_filter(scandir($this->root.'/breads'), fn ($n) => str_starts_with($n, '.') && str_contains($n, '.tmp.'));
        $this->assertSame([], array_values($leftovers), 'Temp files should be cleaned up after successful rename');
    }

    public function test_failed_rename_leaves_original_untouched(): void
    {
        // The cleanest portable way to force rename() to fail is to make
        // the target path a directory — rename of a file ONTO a directory
        // fails with EISDIR/EEXIST regardless of effective uid (root
        // doesn't bypass this).
        //
        // Setup:
        //   1. Write a real recipe at recipes/breads/atomic-test.md.
        //   2. Save its original content.
        //   3. Replace the file with a directory of the same name.
        //   4. Attempt another write — rename should fail.
        //   5. Confirm the temp file is cleaned up AND no original-file
        //      content was clobbered (the directory we put in place is
        //      still there, untouched).
        $original = $this->sampleMarkdown('Original Content');
        $result = $this->writer->write('atomic-test', 'breads', $original);
        $targetPath = $result->path;
        unlink($targetPath);
        mkdir($targetPath, 0755);

        try {
            $this->writer->write('atomic-test', 'breads', $this->sampleMarkdown('Should Not Land'));
            $this->fail('Write should have failed when target is a directory');
        } catch (RuntimeException $e) {
            // Expected. The directory we placed should still be there
            // (we never touched it).
            $this->assertTrue(is_dir($targetPath), 'Target directory should remain after failed rename');
            $leftovers = array_filter(scandir($this->root.'/breads'), fn ($n) => str_starts_with($n, '.') && str_contains($n, '.tmp.'));
            $this->assertSame([], array_values($leftovers), 'Temp files should be cleaned up after a failed write');
        } finally {
            // Clean up the directory we inserted so tearDown can remove
            // the fixture root cleanly.
            @rmdir($targetPath);
        }
    }

    // === Helpers ===

    private function sampleMarkdown(string $title): string
    {
        return "---\ntitle: {$title}\ncategory: breads\nslug: x\n---\n\n## Ingredients\n\n- 1 cup flour\n\n## Method\n\n1. Mix.\n";
    }

    private function rmrf(string $path): void
    {
        if (! is_dir($path)) {
            @unlink($path);
            return;
        }
        @chmod($path, 0755);
        $items = @scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->rmrf($path.'/'.$item);
        }
        @rmdir($path);
    }
}
