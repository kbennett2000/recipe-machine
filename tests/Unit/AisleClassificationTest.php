<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Recipes\ShoppingList\Aisles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AisleClassificationTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_classify(string $ingredient, string $expectedAisle): void
    {
        $this->assertSame($expectedAisle, Aisles::classify($ingredient));
    }

    public static function cases(): array
    {
        return [
            // Phase 6.1 regression case: broth must not classify to Meat & Seafood
            // just because the name contains "chicken".
            'chicken broth → Pantry'           => ['chicken broth',           Aisles::PANTRY],
            'beef broth → Pantry'              => ['beef broth',              Aisles::PANTRY],
            'vegetable broth → Pantry'         => ['vegetable broth',         Aisles::PANTRY],
            'bone broth → Pantry'              => ['bone broth',              Aisles::PANTRY],
            'chicken stock → Pantry'           => ['chicken stock',           Aisles::PANTRY],

            // Meat-name still wins when there is no broth in the name.
            'chicken breast → Meat & Seafood'  => ['chicken breast',          Aisles::MEAT_SEAFOOD],
            'ground beef → Meat & Seafood'     => ['ground beef',             Aisles::MEAT_SEAFOOD],

            // Sanity checks on the rest of the table.
            'flour → Pantry'                   => ['all-purpose flour',       Aisles::PANTRY],
            'salt → Spices'                    => ['salt',                    Aisles::SPICES],
            'kosher salt → Spices'             => ['kosher salt',             Aisles::SPICES],
            'butter → Dairy'                   => ['unsalted butter',         Aisles::DAIRY],
            'milk → Dairy'                     => ['whole milk',              Aisles::DAIRY],
            'garlic cloves → Produce'          => ['garlic cloves',           Aisles::PRODUCE],
            'fresh basil → Produce'            => ['fresh basil',             Aisles::PRODUCE],
            'basil (dried, assumed) → Spices'  => ['basil',                   Aisles::SPICES],
            'yeast → Baking'                   => ['instant yeast',           Aisles::BAKING],
            'cocoa powder → Baking'            => ['cocoa powder',            Aisles::BAKING],
            'chocolate chips → Baking'         => ['chocolate chips',         Aisles::BAKING],

            // Unrecognized ingredients fall back to OTHER.
            'unknown → Other'                  => ['weird made-up thing',     Aisles::OTHER],
            'empty → Other'                    => ['',                        Aisles::OTHER],
        ];
    }
}
