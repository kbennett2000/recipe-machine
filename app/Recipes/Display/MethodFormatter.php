<?php

declare(strict_types=1);

namespace App\Recipes\Display;

/**
 * Highlights temperature mentions and timer phrases inside method-step text.
 *
 * The output is HTML-safe: the input is escaped first, then the regex layer
 * wraps matched phrases in <span class="metric metric-temp"> or
 * metric-timer. Bracketed cross-references [[slug]] are turned into either
 * resolved links or unresolved bold text by the caller (the Blade view), not here.
 */
final class MethodFormatter
{
    private const TEMP_REGEX = '/\b(\d{2,4})\s*°?\s*([FC])\b/u';

    /**
     * Timer phrase: number-or-range followed by a time unit. Supports unicode
     * fractions in the number tokens, en- and em-dashes in the range.
     */
    private const TIMER_REGEX =
        '/\b(\d+(?:[.\/]\d+)?[¼½¾⅓⅔⅛⅜⅝⅞]?(?:\s*[\-–—]\s*\d+(?:[.\/]\d+)?[¼½¾⅓⅔⅛⅜⅝⅞]?)?)\s*(min(?:ute)?s?|hours?|hrs?|h|seconds?|secs?|s)\b/ui';

    public function format(string $stepText): string
    {
        $escaped = htmlspecialchars($stepText, ENT_QUOTES, 'UTF-8');

        // Temperature
        $out = preg_replace_callback(self::TEMP_REGEX, function ($m) {
            return '<span class="metric metric-temp">'.$m[0].'</span>';
        }, $escaped);

        // Timer
        $out = preg_replace_callback(self::TIMER_REGEX, function ($m) {
            return '<span class="metric metric-timer">'.$m[0].'</span>';
        }, (string) $out);

        return (string) $out;
    }
}
