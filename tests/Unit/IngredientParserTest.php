<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Recipes\Parser\IngredientParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class IngredientParserTest extends TestCase
{
    private const FIXTURE_PATH = __DIR__.'/../Fixtures/IngredientLines/lines.yaml';

    #[DataProvider('linesProvider')]
    public function test_parses_line(string $name, string $input, array $expected): void
    {
        $parser = new IngredientParser;
        $result = $parser->parseLine($input);

        $this->assertSame(
            $expected['parsed'] ?? true,
            $result->parsed,
            "[{$name}] parsed flag mismatch — raw: {$result->raw}"
        );

        // For unparseable lines, only `parsed` (and `optional`, when explicitly
        // asserted) are meaningful per spec section c "Unparseable lines".
        if (! $result->parsed) {
            if (array_key_exists('optional', $expected)) {
                $this->assertSame($expected['optional'], $result->optional, "[{$name}] optional mismatch on unparsed line");
            }
            return;
        }

        if (array_key_exists('amount', $expected)) {
            $this->assertEqualsWithDelta($expected['amount'], $result->amount, 1e-9, "[{$name}] amount mismatch");
        }
        if (array_key_exists('amount_high', $expected)) {
            $this->assertEqualsWithDelta($expected['amount_high'], $result->amountHigh, 1e-9, "[{$name}] amount_high mismatch");
        } else {
            $this->assertNull($result->amountHigh, "[{$name}] amount_high expected null");
        }
        if (array_key_exists('unit', $expected)) {
            $this->assertSame($expected['unit'], $result->unit, "[{$name}] unit mismatch");
        }
        if (array_key_exists('ingredient', $expected)) {
            $this->assertSame($expected['ingredient'], $result->ingredient, "[{$name}] ingredient mismatch");
        }
        $this->assertSame($expected['modifier'] ?? null, $result->modifier, "[{$name}] modifier mismatch");
        $this->assertSame($expected['note'] ?? null, $result->note, "[{$name}] note mismatch");
        $this->assertSame($expected['optional'] ?? false, $result->optional, "[{$name}] optional mismatch");
    }

    public static function linesProvider(): array
    {
        $yaml = Yaml::parseFile(self::FIXTURE_PATH);
        $rows = [];
        foreach ($yaml['cases'] as $case) {
            $rows[$case['name']] = [
                $case['name'],
                $case['input'],
                $case['expected'] ?? [],
            ];
        }
        return $rows;
    }

    public function test_raw_is_preserved_after_stripping_bullet(): void
    {
        $parser = new IngredientParser;
        $result = $parser->parseLine('- 2 cups flour');
        $this->assertSame('2 cups flour', $result->raw);
    }

    public function test_raw_preserves_writer_text_on_failed_parse(): void
    {
        $parser = new IngredientParser;
        $result = $parser->parseLine('- A glug of olive oil');
        $this->assertFalse($result->parsed);
        $this->assertSame('A glug of olive oil', $result->raw);
    }

    public function test_group_is_threaded_through(): void
    {
        $parser = new IngredientParser;
        $result = $parser->parseLine('- 1 cup flour', group: 'Dough');
        $this->assertSame('Dough', $result->group);
    }

    public function test_numbered_list_marker_is_stripped(): void
    {
        $parser = new IngredientParser;
        $result = $parser->parseLine('1. 2 cups flour');
        $this->assertSame('2 cups flour', $result->raw);
        $this->assertTrue($result->parsed);
    }
}
