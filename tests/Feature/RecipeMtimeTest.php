<?php

declare(strict_types=1);

namespace Tests\Feature;

/**
 * Phase 11H — mtime polling endpoint tests.
 *
 * The editor polls this every 10s to warn the user when the source file
 * has been modified out-of-band (another editor window, git pull, etc).
 */
final class RecipeMtimeTest extends IndexedCorpusTestCase
{
    private const TEST_SLUG = 'honey-oat-bread';

    private string $sourcePath;

    private string $originalMarkdown;

    private int $originalMtime;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sourcePath = base_path('recipes/breads/'.self::TEST_SLUG.'.md');
        $this->originalMarkdown = file_get_contents($this->sourcePath);
        $this->originalMtime = filemtime($this->sourcePath);
    }

    protected function tearDown(): void
    {
        // Restore file content + mtime so subsequent tests aren't disturbed.
        file_put_contents($this->sourcePath, $this->originalMarkdown);
        touch($this->sourcePath, $this->originalMtime);
        parent::tearDown();
    }

    public function test_get_mtime_returns_current_filesystem_timestamp(): void
    {
        $response = $this->getJson('/recipes/'.self::TEST_SLUG.'/edit/mtime');
        $response->assertStatus(200);
        $response->assertJsonStructure(['mtime']);

        $reported = (int) $response->json('mtime');
        $this->assertSame(filemtime($this->sourcePath), $reported);
    }

    public function test_get_mtime_returns_404_for_unknown_recipe(): void
    {
        $this->getJson('/recipes/no-such-recipe/edit/mtime')->assertStatus(404);
    }

    public function test_after_touch_the_mtime_increases(): void
    {
        $before = (int) $this->getJson('/recipes/'.self::TEST_SLUG.'/edit/mtime')->json('mtime');

        // Bump the mtime forward by 5 seconds — simulates an out-of-band edit.
        touch($this->sourcePath, $before + 5);
        clearstatcache(true, $this->sourcePath);

        $after = (int) $this->getJson('/recipes/'.self::TEST_SLUG.'/edit/mtime')->json('mtime');
        $this->assertGreaterThan($before, $after);
    }

    public function test_edit_page_carries_initial_mtime_into_alpine_component(): void
    {
        $response = $this->get('/recipes/'.self::TEST_SLUG.'/edit');
        $response->assertStatus(200);
        // The mtime is rendered as an integer literal in the recipeEditor()
        // config object, plus as a `data-initial-mtime` attribute on the
        // wrapper. The frontend polling component compares the polled value
        // against this initial number.
        $response->assertSee('initialMtime: '.$this->originalMtime, escape: false);
        $response->assertSee('data-initial-mtime="'.$this->originalMtime.'"', escape: false);
    }

    public function test_edit_page_includes_stale_file_banner_markup(): void
    {
        $response = $this->get('/recipes/'.self::TEST_SLUG.'/edit');
        $response->assertSee('data-testid="stale-file-banner"', escape: false);
        $response->assertSee('The file has changed on disk');
        $response->assertSee('data-testid="stale-banner-reload"', escape: false);
    }
}
