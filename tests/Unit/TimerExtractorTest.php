<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Recipes\Cooking\TimerExtractor;
use App\Recipes\Cooking\TimerMatch;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TimerExtractorTest extends TestCase
{
    private TimerExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new TimerExtractor;
    }

    /**
     * @param  array<array{label:string, seconds:int, low_seconds:?int}>  $expected
     */
    #[DataProvider('cases')]
    public function test_extracts_timers(string $input, array $expected): void
    {
        $matches = $this->extractor->extract($input);
        $actual = array_map(fn (TimerMatch $m) => [
            'label' => $m->raw,
            'seconds' => $m->durationSeconds,
            'low_seconds' => $m->durationLowSeconds,
        ], $matches);
        $this->assertSame($expected, $actual, "Input: {$input}");
    }

    public static function cases(): array
    {
        return [
            // ============ Singles ============
            'plain minutes'        => ['Bake for 35 minutes',          [['label' => '35 minutes', 'seconds' => 2100, 'low_seconds' => null]]],
            'abbreviated min'      => ['Mix 45 min',                   [['label' => '45 min', 'seconds' => 2700, 'low_seconds' => null]]],
            'compact m'            => ['Wait 5m',                      [['label' => '5m', 'seconds' => 300, 'low_seconds' => null]]],
            'plain hours'          => ['Cool 2 hours',                 [['label' => '2 hours', 'seconds' => 7200, 'low_seconds' => null]]],
            'single hour singular' => ['Rest 1 hour',                  [['label' => '1 hour', 'seconds' => 3600, 'low_seconds' => null]]],
            'hr abbreviated'       => ['Cool 1 hr',                    [['label' => '1 hr', 'seconds' => 3600, 'low_seconds' => null]]],
            'compact h'            => ['Refrigerate 2h',               [['label' => '2h', 'seconds' => 7200, 'low_seconds' => null]]],
            'plain seconds'        => ['Whisk 30 seconds',             [['label' => '30 seconds', 'seconds' => 30, 'low_seconds' => null]]],
            'abbreviated sec'      => ['Pulse 15 sec',                 [['label' => '15 sec', 'seconds' => 15, 'low_seconds' => null]]],
            'compact s'            => ['Toast 20s',                    [['label' => '20s', 'seconds' => 20, 'low_seconds' => null]]],

            // ============ Ranges ============
            'hyphen range'         => ['Bake 35-40 minutes at 350°F',  [['label' => '35-40 minutes', 'seconds' => 2400, 'low_seconds' => 2100]]],
            'en-dash range'        => ['Rise 60–90 minutes',           [['label' => '60–90 minutes', 'seconds' => 5400, 'low_seconds' => 3600]]],
            'short-form range'    => ['Knead 8-10 minutes',           [['label' => '8-10 minutes', 'seconds' => 600, 'low_seconds' => 480]]],
            'to-word range'        => ['Wait 30 to 45 minutes',        [['label' => '30 to 45 minutes', 'seconds' => 2700, 'low_seconds' => 1800]]],
            'hour range'           => ['Cool 1-2 hours',               [['label' => '1-2 hours', 'seconds' => 7200, 'low_seconds' => 3600]]],

            // ============ Fractions ============
            'unicode mixed'        => ['Rest 1½ hours',                [['label' => '1½ hours', 'seconds' => 5400, 'low_seconds' => null]]],
            'ASCII mixed'          => ['Cool 1 1/2 hours',             [['label' => '1 1/2 hours', 'seconds' => 5400, 'low_seconds' => null]]],
            'bare unicode'         => ['Wait ½ hour',                  [['label' => '½ hour', 'seconds' => 1800, 'low_seconds' => null]]],
            'unicode range'        => ['Rise 1–1½ hours',              [['label' => '1–1½ hours', 'seconds' => 5400, 'low_seconds' => 3600]]],
            'decimal'              => ['Cool 1.5 hours',               [['label' => '1.5 hours', 'seconds' => 5400, 'low_seconds' => null]]],

            // ============ Compounds ============
            'compact compound'     => ['Bake 1h30m',                   [['label' => '1h30m', 'seconds' => 5400, 'low_seconds' => null]]],
            'spaced compound'      => ['Rest 1 hour 30 minutes',       [['label' => '1 hour 30 minutes', 'seconds' => 5400, 'low_seconds' => null]]],

            // ============ Cross-unit ranges (Phase 7.1) ============
            // The connector is always "to" — recipes don't write things like
            // "30 minutes-1 hour" with units on both sides.
            'cross-unit minutes to hour' => [
                'Wrap dough and let rest for 30 minutes to 1 hour.',
                [['label' => '30 minutes to 1 hour', 'seconds' => 3600, 'low_seconds' => 1800]],
            ],
            'cross-unit seconds to minutes' => [
                'Bake 45 seconds to 2 minutes',
                [['label' => '45 seconds to 2 minutes', 'seconds' => 120, 'low_seconds' => 45]],
            ],
            // Same-unit range must still produce a single timer — the new
            // cross-unit branch must not regress here.
            'same-unit range still works' => [
                'Cook 15-20 minutes',
                [['label' => '15-20 minutes', 'seconds' => 1200, 'low_seconds' => 900]],
            ],

            // ============ Negative / non-matches ============
            'no timer in plain text'            => ['Mix until smooth',                  []],
            'temperature not a timer'           => ['Heat to 350°F',                     []],
            'two temperatures not timers'       => ['Cool to 110°F (43°C)',              []],
            'bare number'                       => ['Use 3 cloves',                      []],

            // ============ Multiple timers in one step ============
            'two singles same step' => [
                'Rise 60-90 minutes, then bake 35 minutes',
                [
                    ['label' => '60-90 minutes', 'seconds' => 5400, 'low_seconds' => 3600],
                    ['label' => '35 minutes',    'seconds' => 2100, 'low_seconds' => null],
                ],
            ],
            'temp and timer mixed' => [
                'Bake at 350°F for 35-40 minutes',
                [['label' => '35-40 minutes', 'seconds' => 2400, 'low_seconds' => 2100]],
            ],
            'rest then bake' => [
                'Rest 30 min, then bake 1 hour',
                [
                    ['label' => '30 min', 'seconds' => 1800, 'low_seconds' => null],
                    ['label' => '1 hour', 'seconds' => 3600, 'low_seconds' => null],
                ],
            ],

            // ============ Edge cases / honey-oat-bread real corpus ============
            'honey oat bread step 1' => [
                'Knead, rise 1–1½ hours, shape into loaf pan, rise 45–60 min.',
                [
                    ['label' => '1–1½ hours', 'seconds' => 5400, 'low_seconds' => 3600],
                    ['label' => '45–60 min',  'seconds' => 3600, 'low_seconds' => 2700],
                ],
            ],
            'honey oat bread step 2' => [
                'Bake **35–40 minutes at 350°F** (brush with butter after baking).',
                [['label' => '35–40 minutes', 'seconds' => 2400, 'low_seconds' => 2100]],
            ],
        ];
    }
}
