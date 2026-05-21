<?php

declare(strict_types=1);

/*
 * Recipe Machine — app-specific configuration.
 *
 * Phase 9 introduces the LLM ingredient-parser fallback. When the
 * rules-based parser can't structure an ingredient line (parsed=false),
 * the reindex command can optionally route those lines through the
 * Anthropic API. Results are cached forever (hits) or for 30 days
 * (misses, in case the model improves). See:
 *
 *   app/Recipes/LLM/IngredientLLMParser.php
 *   app/Console/Commands/LLMParseFallbackRecipes.php
 *
 * The feature is OFF by default. Set RECIPE_MACHINE_LLM_FALLBACK=true
 * in .env and provide ANTHROPIC_API_KEY to enable.
 */
return [
    'llm' => [
        'enabled' => env('RECIPE_MACHINE_LLM_FALLBACK', false),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-haiku-4-5-20251001'),
        'cache_tombstone_ttl_days' => 30,
        'batch_size' => 20,
        'timeout_seconds' => 30,
        'api_base_url' => env('ANTHROPIC_API_BASE_URL', 'https://api.anthropic.com'),
    ],
];
