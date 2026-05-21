<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Recipes\Parser\Frontmatter;
use App\Recipes\Parser\ParsedIngredient;
use App\Recipes\Parser\ParsedRecipe;
use App\Recipes\Parser\RecipeParser;
use App\Recipes\Serializer\RecipeSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Phase 11A — the contract.
 *
 * For every recipe in the corpus:
 *   1. Parse the source markdown → ParsedRecipe A
 *   2. Serialize A back to markdown via RecipeSerializer → string M
 *   3. Parse M → ParsedRecipe B
 *   4. Assert A and B are structurally equal across every field
 *      (excluding parseWarnings, which are informational and can vary
 *      between runs of the parser).
 *
 * If any recipe fails the round-trip, the test surfaces a diff naming
 * the diverging field so the serializer bug is easy to fix.
 *
 * Phase 11A.1: LLM-derived ingredients with amount_high-only shapes
 * (the "Up to N unit X" pattern) now round-trip cleanly — the parser
 * was extended to recognize the "up to" prefix and the trailing-period
 * unit abbreviation. The earlier caveat about LLM-derived lines is
 * resolved; serialize → re-parse is structurally sound for any shape
 * the parser produces or the formatter emits.
 */
final class RecipeSerializerParityTest extends TestCase
{
    #[DataProvider('corpusFiles')]
    public function test_corpus_recipe_round_trips(string $path): void
    {
        $parser = new RecipeParser;
        $serializer = new RecipeSerializer;

        $a = $parser->parseFile($path);
        $markdown = $serializer->serialize($a);

        try {
            $b = $parser->parseString($markdown);
        } catch (\Throwable $e) {
            $this->fail(sprintf(
                "Re-parse of serialized output FAILED for %s:\n  %s\n\nSerialized output was:\n%s",
                basename($path), $e->getMessage(), $markdown,
            ));
        }

        $divergences = $this->compareRecipes($a, $b);
        if ($divergences !== []) {
            $this->fail(sprintf(
                "Round-trip divergence for %s:\n%s\n\nSerialized output was:\n%s",
                basename($path),
                implode("\n", array_map(fn ($d) => '  - '.$d, $divergences)),
                $markdown,
            ));
        }
        $this->assertTrue(true);
    }

    public static function corpusFiles(): array
    {
        $root = dirname(__DIR__, 2).'/recipes';
        $files = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root)) as $entry) {
            /** @var \SplFileInfo $entry */
            if ($entry->isFile() && $entry->getExtension() === 'md') {
                $files[basename(dirname($entry->getPathname())).'/'.$entry->getBasename()] = [$entry->getPathname()];
            }
        }
        ksort($files);
        return $files;
    }

    /**
     * @return list<string>  Human-readable divergence descriptions.
     */
    private function compareRecipes(ParsedRecipe $a, ParsedRecipe $b): array
    {
        $diff = [];

        $fmDiff = $this->compareFrontmatter($a->frontmatter, $b->frontmatter);
        if ($fmDiff !== []) {
            foreach ($fmDiff as $line) {
                $diff[] = 'frontmatter: '.$line;
            }
        }

        if (count($a->ingredients) !== count($b->ingredients)) {
            $diff[] = sprintf(
                'ingredients: count %d → %d',
                count($a->ingredients), count($b->ingredients),
            );
        } else {
            foreach ($a->ingredients as $i => $ingA) {
                $ingB = $b->ingredients[$i];
                $ingDiff = $this->compareIngredient($ingA, $ingB);
                foreach ($ingDiff as $line) {
                    $diff[] = "ingredients[$i]: $line  (raw A=".json_encode($ingA->raw).')';
                }
            }
        }

        if ($a->method !== $b->method) {
            // Pinpoint the first diverging step.
            $max = max(count($a->method), count($b->method));
            for ($i = 0; $i < $max; $i++) {
                $stepA = $a->method[$i] ?? null;
                $stepB = $b->method[$i] ?? null;
                if ($stepA !== $stepB) {
                    $diff[] = sprintf(
                        "method[%d]: %s → %s",
                        $i,
                        $stepA === null ? '(absent)' : json_encode($stepA),
                        $stepB === null ? '(absent)' : json_encode($stepB),
                    );
                    break;
                }
            }
        }

        if ($a->notes !== $b->notes) {
            $diff[] = sprintf(
                'notes: %s → %s',
                $a->notes === null ? '(null)' : json_encode(substr($a->notes, 0, 80).(strlen($a->notes ?? '') > 80 ? '…' : '')),
                $b->notes === null ? '(null)' : json_encode(substr($b->notes, 0, 80).(strlen($b->notes ?? '') > 80 ? '…' : '')),
            );
        }
        if ($a->libationProse !== $b->libationProse) {
            $diff[] = sprintf(
                'libation_prose: %s → %s',
                json_encode($a->libationProse),
                json_encode($b->libationProse),
            );
        }

        // Cross-references compared as sets (parser may dedupe or sort them).
        $crA = $a->crossReferences;
        $crB = $b->crossReferences;
        sort($crA);
        sort($crB);
        if ($crA !== $crB) {
            $diff[] = sprintf('cross_references: %s → %s', json_encode($crA), json_encode($crB));
        }

        return $diff;
    }

    /** @return list<string> */
    private function compareFrontmatter(Frontmatter $a, Frontmatter $b): array
    {
        $diff = [];
        foreach ($a->toArray() as $key => $valueA) {
            $valueB = $b->toArray()[$key] ?? null;
            if ($valueA !== $valueB) {
                $diff[] = sprintf('%s: %s → %s', $key, json_encode($valueA), json_encode($valueB));
            }
        }
        return $diff;
    }

    /** @return list<string> */
    private function compareIngredient(ParsedIngredient $a, ParsedIngredient $b): array
    {
        $diff = [];
        $aArr = $a->toArray();
        $bArr = $b->toArray();
        // `raw` is allowed to differ — the parser stores the source bullet
        // text minus the marker, and our serializer reconstructs a canonical
        // form (e.g. "Optional: ..." prefix). What matters is that the
        // STRUCTURED fields round-trip; the raw is a presentation detail.
        unset($aArr['raw'], $bArr['raw']);
        foreach ($aArr as $key => $vA) {
            $vB = $bArr[$key] ?? null;
            if ($vA !== $vB) {
                $diff[] = sprintf('%s: %s → %s', $key, json_encode($vA), json_encode($vB));
            }
        }
        return $diff;
    }
}
