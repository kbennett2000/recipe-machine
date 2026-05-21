<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Base class for feature tests that need the full corpus indexed.
 *
 * Each test starts with a fresh in-memory SQLite (per phpunit.xml), so we run
 * `recipes:reindex` against the real recipes/ directory in setUp() to populate
 * it. ~150ms per test — acceptable for the small number of feature tests.
 */
abstract class IndexedCorpusTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('recipes:reindex')->assertSuccessful();
    }
}
