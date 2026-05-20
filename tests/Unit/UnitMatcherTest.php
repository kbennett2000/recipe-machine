<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Recipes\Parser\UnitClass;
use App\Recipes\Parser\UnitMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UnitMatcherTest extends TestCase
{
    private UnitMatcher $matcher;

    protected function setUp(): void
    {
        $this->matcher = new UnitMatcher;
    }

    #[DataProvider('positiveProvider')]
    public function test_matches_canonical_unit(
        string $input,
        string $expectedCanonical,
        UnitClass $expectedClass,
        string $expectedInput,
    ): void {
        $result = $this->matcher->match($input);
        $this->assertNotNull($result, "Expected a match for `{$input}`");
        $this->assertSame($expectedCanonical, $result->canonical, "canonical");
        $this->assertSame($expectedClass, $result->class, "class");
        $this->assertSame($expectedInput, $result->input, "input preserves writer's spelling");
    }

    public static function positiveProvider(): array
    {
        return [
            // Volume
            'cups followed by ingredient' => ['cups flour',         'cup',    UnitClass::VOLUME, 'cups'],
            'Tablespoons capitalised'     => ['Tablespoons honey',  'tbsp',   UnitClass::VOLUME, 'Tablespoons'],
            'multi-word fl oz'            => ['fl oz vanilla',      'floz',   UnitClass::VOLUME, 'fl oz'],
            'multi-word fluid ounce'      => ['fluid ounce cream',  'floz',   UnitClass::VOLUME, 'fluid ounce'],
            'ml metric'                   => ['ml water',           'ml',     UnitClass::VOLUME, 'ml'],
            'L standalone capital'        => ['L milk',             'l',      UnitClass::VOLUME, 'L'],

            // Weight
            'g grams'                     => ['g butter',           'g',      UnitClass::WEIGHT, 'g'],
            'pounds full word'            => ['pounds ground beef', 'lb',     UnitClass::WEIGHT, 'pounds'],
            'oz weight by default'        => ['oz cream cheese',    'oz',     UnitClass::WEIGHT, 'oz'],

            // Count
            'cloves count noun'           => ['cloves garlic',      'whole',  UnitClass::COUNT,  'cloves'],
            'stick singular count noun'   => ['stick butter',       'whole',  UnitClass::COUNT,  'stick'],
            'slices count noun'           => ['slices bread',       'whole',  UnitClass::COUNT,  'slices'],

            // Imprecise (standalone tokens — phrase patterns belong to IngredientParser)
            'pinch standalone'            => ['pinch salt',         'pinch',  UnitClass::IMPRECISE, 'pinch'],
            'to taste two-word'           => ['to taste',           'to-taste', UnitClass::IMPRECISE, 'to taste'],
            'as-needed hyphenated'        => ['as-needed',          'as-needed', UnitClass::IMPRECISE, 'as-needed'],
        ];
    }

    #[DataProvider('negativeProvider')]
    public function test_does_not_match_non_units(string $input): void
    {
        $this->assertNull(
            $this->matcher->match($input),
            "Did not expect a match for `{$input}`"
        );
    }

    public static function negativeProvider(): array
    {
        return [
            'empty string'                 => [''],
            'whitespace only'              => ["   "],
            'random word'                  => ['flour'],
            'non-canonical container'      => ['box pasta'],
            'cup as prefix of cupcake'     => ['cupcake'],
            // Phase 2A.1: single-letter forms are no longer supported.
            'capital T standalone'         => ['T sugar'],
            'lowercase t standalone'       => ['t cinnamon'],
            'lowercase c standalone'       => ['c flour'],
            'hash standalone'              => ['# beef'],
        ];
    }

    public function test_multi_word_unit_is_preferred_over_single_word(): void
    {
        // "fl oz" should win over the single-word "oz" since both could match
        // the start of "fl oz vanilla" (well, only fl oz really matches the
        // full prefix — this asserts longest-match wins on the matched input).
        $result = $this->matcher->match('fl oz vanilla');
        $this->assertNotNull($result);
        $this->assertSame('floz', $result->canonical);
        $this->assertSame('fl oz', $result->input);
    }

    public function test_boundary_check_prevents_prefix_collision(): void
    {
        // "cupcake" must NOT match "cup" because there is no whitespace
        // boundary after the canonical spelling.
        $this->assertNull($this->matcher->match('cupcake'));
        // Same logic for "grams" vs "gramophone".
        $this->assertNull($this->matcher->match('gramophone'));
    }

    public function test_match_at_end_of_string_is_allowed(): void
    {
        // No trailing whitespace — the boundary check accepts EOS.
        $result = $this->matcher->match('tsp');
        $this->assertNotNull($result);
        $this->assertSame('tsp', $result->canonical);
        $this->assertSame('tsp', $result->input);
    }
}
