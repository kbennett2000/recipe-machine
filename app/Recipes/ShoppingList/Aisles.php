<?php

declare(strict_types=1);

namespace App\Recipes\ShoppingList;

/**
 * Aisle classification for shopping list grouping.
 *
 * Maps lowercased ingredient names to one of seven aisles. Uses
 * longest-substring-match-wins so "all-purpose flour" matches the
 * "flour" entry, and "brown sugar" matches "brown sugar" over the
 * shorter "sugar".
 *
 * The table is small (~100 entries) and deliberately incomplete —
 * "Other" is the fallback for anything we don't recognize. Each entry
 * is a partial substring (case-insensitive). Adding entries is a
 * one-line change; this is meant to grow as the corpus does.
 *
 * Some judgment calls (documented in the Phase 6 report):
 *   - butter → Dairy (not Baking, even though it's a baking workhorse)
 *   - olive oil → Pantry (lives next to vinegar in most stores)
 *   - sugar → Pantry; brown sugar / powdered sugar also Pantry
 *   - yeast / baking soda / baking powder → Baking
 *   - eggs → Dairy (US store layout convention)
 *   - dried herbs → Spices; fresh herbs → Produce (defaults to Spices
 *     when ambiguous since dried is more common in pantry stocking)
 *   - chocolate chips / cocoa → Baking
 */
final class Aisles
{
    public const PRODUCE = 'Produce';
    public const DAIRY = 'Dairy';
    public const MEAT_SEAFOOD = 'Meat & Seafood';
    public const PANTRY = 'Pantry';
    public const BAKING = 'Baking';
    public const SPICES = 'Spices';
    public const OTHER = 'Other';

    /**
     * Aisle order for output. Stores tend to lay things out roughly
     * in this order; matching the typical traversal makes the printed
     * list easier to shop.
     */
    public const AISLE_ORDER = [
        self::PRODUCE,
        self::MEAT_SEAFOOD,
        self::DAIRY,
        self::BAKING,
        self::PANTRY,
        self::SPICES,
        self::OTHER,
    ];

    /**
     * Substring → aisle mapping. Lower-case keys. Longest-match-wins.
     *
     * @var array<string,string>
     */
    private const RULES = [
        // === Produce ===
        'garlic clove' => self::PRODUCE,
        'garlic' => self::PRODUCE,
        'onion' => self::PRODUCE,
        'shallot' => self::PRODUCE,
        'scallion' => self::PRODUCE,
        'leek' => self::PRODUCE,
        'tomato' => self::PRODUCE,
        'potato' => self::PRODUCE,
        'sweet potato' => self::PRODUCE,
        'lemon' => self::PRODUCE,
        'lime' => self::PRODUCE,
        'orange' => self::PRODUCE,
        'apple' => self::PRODUCE,
        'pear' => self::PRODUCE,
        'banana' => self::PRODUCE,
        'avocado' => self::PRODUCE,
        'cucumber' => self::PRODUCE,
        'lettuce' => self::PRODUCE,
        'iceberg' => self::PRODUCE,
        'spinach' => self::PRODUCE,
        'arugula' => self::PRODUCE,
        'kale' => self::PRODUCE,
        'cabbage' => self::PRODUCE,
        'carrot' => self::PRODUCE,
        'celery' => self::PRODUCE,
        'mushroom' => self::PRODUCE,
        'bell pepper' => self::PRODUCE,
        'jalapeño' => self::PRODUCE,
        'jalapeno' => self::PRODUCE,
        'chili pepper' => self::PRODUCE,
        'broccoli' => self::PRODUCE,
        'cauliflower' => self::PRODUCE,
        'asparagus' => self::PRODUCE,
        'zucchini' => self::PRODUCE,
        'eggplant' => self::PRODUCE,
        'corn' => self::PRODUCE,
        'fennel' => self::PRODUCE,
        'fresh parsley' => self::PRODUCE,
        'fresh basil' => self::PRODUCE,
        'fresh dill' => self::PRODUCE,
        'fresh ginger' => self::PRODUCE,
        'fresh thyme' => self::PRODUCE,
        'fresh rosemary' => self::PRODUCE,
        'fresh mint' => self::PRODUCE,
        'fresh cilantro' => self::PRODUCE,
        'fresh herb' => self::PRODUCE,
        'pickle' => self::PRODUCE,         // dill pickles, etc

        // === Dairy ===
        'milk' => self::DAIRY,
        'butter' => self::DAIRY,
        'cheese' => self::DAIRY,
        'cheddar' => self::DAIRY,
        'mozzarella' => self::DAIRY,
        'parmesan' => self::DAIRY,
        'ricotta' => self::DAIRY,
        'pecorino' => self::DAIRY,
        'cream cheese' => self::DAIRY,
        'yogurt' => self::DAIRY,
        'sour cream' => self::DAIRY,
        'heavy cream' => self::DAIRY,
        'half and half' => self::DAIRY,
        'whipped cream' => self::DAIRY,
        'buttermilk' => self::DAIRY,
        'egg' => self::DAIRY,              // matches "egg", "eggs", "egg yolk", "egg white"

        // === Meat & Seafood ===
        'chicken' => self::MEAT_SEAFOOD,
        'beef' => self::MEAT_SEAFOOD,
        'pork' => self::MEAT_SEAFOOD,
        'bacon' => self::MEAT_SEAFOOD,
        'sausage' => self::MEAT_SEAFOOD,
        'turkey' => self::MEAT_SEAFOOD,
        'lamb' => self::MEAT_SEAFOOD,
        'ham' => self::MEAT_SEAFOOD,
        'shrimp' => self::MEAT_SEAFOOD,
        'salmon' => self::MEAT_SEAFOOD,
        'trout' => self::MEAT_SEAFOOD,
        'cod' => self::MEAT_SEAFOOD,
        'tilapia' => self::MEAT_SEAFOOD,
        'fish' => self::MEAT_SEAFOOD,
        'tuna' => self::MEAT_SEAFOOD,
        'scallops' => self::MEAT_SEAFOOD,
        'crab' => self::MEAT_SEAFOOD,
        'lobster' => self::MEAT_SEAFOOD,

        // === Baking ===
        'yeast' => self::BAKING,
        'baking soda' => self::BAKING,
        'baking powder' => self::BAKING,
        'vanilla extract' => self::BAKING,
        'almond extract' => self::BAKING,
        'chocolate chips' => self::BAKING,
        'cocoa powder' => self::BAKING,
        'baking chocolate' => self::BAKING,
        'condensed milk' => self::BAKING,
        'evaporated milk' => self::BAKING,
        'food coloring' => self::BAKING,
        'sprinkles' => self::BAKING,

        // === Pantry === (flours, sugars, oils, etc.)
        'brown sugar' => self::PANTRY,
        'powdered sugar' => self::PANTRY,
        'granulated sugar' => self::PANTRY,
        'white sugar' => self::PANTRY,
        'sugar' => self::PANTRY,
        'all-purpose flour' => self::PANTRY,
        'whole wheat flour' => self::PANTRY,
        'bread flour' => self::PANTRY,
        'flour' => self::PANTRY,
        'rolled oats' => self::PANTRY,
        'oats' => self::PANTRY,
        'cornmeal' => self::PANTRY,
        'olive oil' => self::PANTRY,
        'vegetable oil' => self::PANTRY,
        'canola oil' => self::PANTRY,
        'sesame oil' => self::PANTRY,
        'oil' => self::PANTRY,
        'red wine vinegar' => self::PANTRY,
        'apple cider vinegar' => self::PANTRY,
        'rice wine vinegar' => self::PANTRY,
        'balsamic vinegar' => self::PANTRY,
        'vinegar' => self::PANTRY,
        'soy sauce' => self::PANTRY,
        'hot sauce' => self::PANTRY,
        'worcestershire' => self::PANTRY,
        'mustard' => self::PANTRY,
        'mayonnaise' => self::PANTRY,
        'mayo' => self::PANTRY,
        'ketchup' => self::PANTRY,
        'honey' => self::PANTRY,
        'maple syrup' => self::PANTRY,
        'pasta' => self::PANTRY,
        'shells' => self::PANTRY,           // jumbo pasta shells
        'rice' => self::PANTRY,
        'beans' => self::PANTRY,
        'pinto beans' => self::PANTRY,
        'black beans' => self::PANTRY,
        'breadcrumbs' => self::PANTRY,
        'panko' => self::PANTRY,
        // Specific broth/stock variants (Phase 6.1 fix). These must outrank the
        // meat-name rules below; longest-match-wins gives us that for free.
        'chicken broth' => self::PANTRY,
        'chicken stock' => self::PANTRY,
        'beef broth' => self::PANTRY,
        'beef stock' => self::PANTRY,
        'vegetable broth' => self::PANTRY,
        'vegetable stock' => self::PANTRY,
        'bone broth' => self::PANTRY,
        'broth' => self::PANTRY,
        'stock' => self::PANTRY,
        'tomato sauce' => self::PANTRY,
        'tomato paste' => self::PANTRY,
        'crushed tomatoes' => self::PANTRY,
        'diced tomatoes' => self::PANTRY,
        'pineapple juice' => self::PANTRY,
        'capers' => self::PANTRY,
        'walnuts' => self::PANTRY,
        'nuts' => self::PANTRY,
        'starter' => self::PANTRY,          // sourdough starter

        // === Spices ===
        'salt' => self::SPICES,
        'pepper' => self::SPICES,
        'kosher salt' => self::SPICES,
        'sea salt' => self::SPICES,
        'black pepper' => self::SPICES,
        'cinnamon' => self::SPICES,
        'nutmeg' => self::SPICES,
        'paprika' => self::SPICES,
        'smoked paprika' => self::SPICES,
        'cayenne' => self::SPICES,
        'chili powder' => self::SPICES,
        'chili seasoning' => self::SPICES,
        'cumin' => self::SPICES,
        'coriander' => self::SPICES,
        'oregano' => self::SPICES,
        'basil' => self::SPICES,
        'thyme' => self::SPICES,
        'rosemary' => self::SPICES,
        'dill' => self::SPICES,
        'parsley' => self::SPICES,
        'garlic powder' => self::SPICES,
        'onion powder' => self::SPICES,
        'italian seasoning' => self::SPICES,
        'creole mustard' => self::SPICES,
        'dijon' => self::SPICES,
        'chili flakes' => self::SPICES,
    ];

    /**
     * Classify an ingredient name into an aisle. Falls back to OTHER
     * when nothing matches.
     */
    public static function classify(string $ingredientName): string
    {
        $name = mb_strtolower(trim($ingredientName));
        if ($name === '') {
            return self::OTHER;
        }

        // Longest-match-wins: sort rule keys by length descending and find the first substring hit.
        static $sorted = null;
        if ($sorted === null) {
            $keys = array_keys(self::RULES);
            usort($keys, fn ($a, $b) => strlen($b) - strlen($a));
            $sorted = $keys;
        }

        foreach ($sorted as $needle) {
            if (str_contains($name, $needle)) {
                return self::RULES[$needle];
            }
        }
        return self::OTHER;
    }
}
