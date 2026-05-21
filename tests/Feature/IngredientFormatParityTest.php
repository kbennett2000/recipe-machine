<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Recipes\Display\IngredientFormatter;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Asserts the JavaScript ingredient formatter at
 * resources/js/ingredient-format.js produces byte-identical output to the
 * PHP IngredientFormatter for every case in this file.
 *
 * Implementation: build the cases in PHP, get the PHP outputs, then exec
 * `node tests/scripts/run-formatter.mjs` with the cases on stdin, parse the
 * JS outputs back, and assert each pair.
 *
 * This is the only place the two formatters meet. If they diverge, this test
 * is the single source of truth for which one is wrong.
 */
final class IngredientFormatParityTest extends TestCase
{
    public function test_php_and_js_formatters_agree_on_every_case(): void
    {
        $cases = $this->cases();
        $this->assertGreaterThanOrEqual(30, count($cases), 'Parity test must cover at least 30 cases.');

        // PHP side.
        $formatter = new IngredientFormatter;
        $phpOutputs = array_map(fn (array $c) => $formatter->formatFields($c), $cases);

        // JS side via Node.
        $jsOutputs = $this->runJsFormatter($cases);

        $this->assertCount(count($phpOutputs), $jsOutputs,
            'JS runner returned the wrong number of outputs.');

        $divergences = [];
        foreach ($cases as $i => $case) {
            if ($phpOutputs[$i] !== $jsOutputs[$i]) {
                $divergences[] = sprintf(
                    "[case %d] input=%s\n   PHP: %s\n   JS : %s",
                    $i, json_encode($case), var_export($phpOutputs[$i], true), var_export($jsOutputs[$i], true)
                );
            }
        }

        $this->assertSame(
            [],
            $divergences,
            "PHP/JS formatter divergence:\n\n".implode("\n\n", $divergences)
        );
    }

    /**
     * @param  array<array<string,mixed>>  $cases
     * @return array<string>
     */
    private function runJsFormatter(array $cases): array
    {
        $script = realpath(__DIR__.'/../scripts/run-formatter.mjs');
        if ($script === false) {
            throw new RuntimeException('Cannot find run-formatter.mjs');
        }
        $payload = json_encode($cases);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(['node', $script], $descriptors, $pipes);
        if (! is_resource($proc)) {
            $this->markTestSkipped('Node not available — parity test skipped.');
        }
        fwrite($pipes[0], $payload);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]) ?: '';
        $stderr = stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if ($code !== 0) {
            throw new RuntimeException("Node runner failed (exit {$code}): {$stderr}");
        }

        $result = json_decode($stdout, true);
        if (! is_array($result)) {
            throw new RuntimeException("Node runner returned non-array: {$stdout}");
        }
        return array_map('strval', $result);
    }

    /**
     * Hand-curated parity cases. Each case is the array form of an Ingredient
     * record (matching IngredientFormatter::formatFields signature) plus
     * notation in code for the situation it exercises.
     *
     * @return array<array<string,mixed>>
     */
    private function cases(): array
    {
        return [
            // === Whole numbers + canonical units ===
            ['amount' => 1, 'unit' => 'cup', 'ingredient' => 'flour'],
            ['amount' => 2, 'unit' => 'cup', 'ingredient' => 'flour'],
            ['amount' => 3, 'unit' => 'tsp', 'ingredient' => 'salt'],
            ['amount' => 100, 'unit' => 'g', 'ingredient' => 'butter'],
            ['amount' => 1, 'unit' => 'lb', 'ingredient' => 'ground beef'],

            // === Common fractions ===
            ['amount' => 0.5, 'unit' => 'cup', 'ingredient' => 'butter'],
            ['amount' => 0.25, 'unit' => 'cup', 'ingredient' => 'sugar'],
            ['amount' => 0.333, 'unit' => 'cup', 'ingredient' => 'milk'],
            ['amount' => 0.75, 'unit' => 'cup', 'ingredient' => 'flour'],
            ['amount' => 0.667, 'unit' => 'cup', 'ingredient' => 'water'],
            ['amount' => 0.125, 'unit' => 'tsp', 'ingredient' => 'salt'],
            ['amount' => 0.875, 'unit' => 'cup', 'ingredient' => 'cream'],

            // === Mixed numbers ===
            ['amount' => 1.5, 'unit' => 'cup', 'ingredient' => 'flour'],
            ['amount' => 2.25, 'unit' => 'tsp', 'ingredient' => 'yeast'],
            ['amount' => 1.25, 'unit' => 'cup', 'ingredient' => 'sugar'],
            ['amount' => 1.75, 'unit' => 'cup', 'ingredient' => 'water'],

            // === Ranges ===
            ['amount' => 2, 'amount_high' => 3, 'unit' => 'cup', 'ingredient' => 'water'],
            ['amount' => 1, 'amount_high' => 2, 'unit' => 'tbsp', 'ingredient' => 'oil'],
            ['amount' => 4, 'amount_high' => 5, 'unit' => 'tbsp', 'ingredient' => 'lard'],

            // === Imprecise leading ===
            ['amount' => null, 'unit' => 'pinch', 'unit_class' => 'imprecise', 'ingredient' => 'salt'],
            ['amount' => null, 'unit' => 'dash', 'unit_class' => 'imprecise', 'ingredient' => 'cayenne'],
            ['amount' => null, 'unit' => 'handful', 'unit_class' => 'imprecise', 'ingredient' => 'arugula'],
            ['amount' => null, 'unit' => 'drizzle', 'unit_class' => 'imprecise', 'ingredient' => 'olive oil'],

            // === Imprecise trailing ===
            ['amount' => null, 'unit' => 'to-taste', 'unit_class' => 'imprecise', 'ingredient' => 'salt'],
            ['amount' => null, 'unit' => 'as-needed', 'unit_class' => 'imprecise', 'ingredient' => 'olive oil'],

            // === Whole / count items (unit=whole) ===
            ['amount' => 3, 'unit' => 'whole', 'ingredient' => 'eggs'],
            ['amount' => 1, 'unit' => 'whole', 'ingredient' => 'large onion'],
            ['amount' => 2, 'amount_high' => 3, 'unit' => 'whole', 'ingredient' => 'garlic cloves'],

            // === Whole + non-integer → ~ prefix ===
            ['amount' => 4.5, 'unit' => 'whole', 'ingredient' => 'eggs'],
            ['amount' => 1.5, 'unit' => 'whole', 'ingredient' => 'large onions'],
            ['amount' => 3, 'amount_high' => 4.5, 'unit' => 'whole', 'ingredient' => 'garlic cloves'],

            // === With modifier ===
            ['amount' => 0.5, 'unit' => 'cup', 'ingredient' => 'butter', 'modifier' => 'softened'],
            ['amount' => 2, 'unit' => 'whole', 'ingredient' => 'large eggs', 'modifier' => 'beaten'],

            // === Optional ===
            ['amount' => 1, 'unit' => 'whole', 'ingredient' => 'egg', 'optional' => true],
            ['amount' => 0.5, 'unit' => 'cup', 'ingredient' => 'walnuts', 'modifier' => 'chopped', 'optional' => true],

            // === Decimal fallback (no fraction match) ===
            ['amount' => 0.4, 'unit' => 'cup', 'ingredient' => 'sugar'],
            ['amount' => 0.35, 'unit' => 'tsp', 'ingredient' => 'salt'],
            ['amount' => 0.625, 'unit' => 'lb', 'ingredient' => 'butter'],

            // === Edge: amountIsPlural with range (high > 1) ===
            ['amount' => 0.5, 'amount_high' => 1.5, 'unit' => 'cup', 'ingredient' => 'sauce'],
        ];
    }
}
