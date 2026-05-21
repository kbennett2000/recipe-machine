<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Recipes\Search\MatchQueryBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MatchQueryBuilderTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_build(string $input, ?string $expected): void
    {
        $builder = new MatchQueryBuilder;
        $this->assertSame($expected, $builder->build($input));
    }

    public static function cases(): array
    {
        return [
            'empty input → null'                  => ['', null],
            'whitespace only → null'              => ['   ', null],
            'all-punctuation → null'              => ['!!! ???', null],
            'single word'                         => ['butter', '"butter"'],
            'two words ANDed'                     => ['butter flour', '"butter" "flour"'],
            'phrase quoted'                       => ['"no knead"', '"no knead"'],
            'phrase plus word'                    => ['"no knead" bread', '"no knead" "bread"'],
            'hyphen becomes space inside token'   => ['no-knead', '"no knead"'],
            'special chars stripped'              => ['butter:flour*', '"butter" "flour"'],
            'unicode preserved'                   => ['crème', '"crème"'],
            'multi-word phrase preserves order'   => ['"olive oil garlic"', '"olive oil garlic"'],
        ];
    }
}
