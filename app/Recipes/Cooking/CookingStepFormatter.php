<?php

declare(strict_types=1);

namespace App\Recipes\Cooking;

/**
 * Renders a single method-step's raw text into the HTML form used in cooking
 * mode: timer phrases become tappable `<button class="timer-btn">`s with data
 * attributes the Alpine component consumes, and temperature mentions get
 * the same `<span class="metric metric-temp">` styling as on the standard
 * recipe page.
 *
 * Order of operations (per the Phase 7 brief — both committed and documented):
 *   1. Collect timer matches via TimerExtractor.
 *   2. Collect temperature matches via the in-class regex below.
 *   3. Merge into a single sorted list of substitutions by byte offset,
 *      drop any pair that overlap (timer and temp regexes don't overlap in
 *      practice, but defense in depth).
 *   4. Walk the raw text once, emitting HTML-escaped prose between matches
 *      and the appropriate markup for each match. This avoids any
 *      "regex-on-already-escaped-HTML" gotchas.
 *
 * Markdown-bold pre-process: the bread codex sources often write
 * `**35–40 minutes at 350°F**` to emphasize critical timing. The cooking
 * view converts those to `<strong>` blocks so the emphasis carries through.
 */
final class CookingStepFormatter
{
    private const TEMP_REGEX = '/\b(\d{2,4})\s*°?\s*([FC])\b/u';

    public function __construct(
        private readonly TimerExtractor $timerExtractor = new TimerExtractor,
    ) {}

    public function format(string $stepText): string
    {
        // Bold ranges are treated as containers, not substitutions: we split
        // the text into bold and non-bold spans, then run timer/temp matching
        // on each span independently. This way `**35–40 minutes at 350°F**`
        // becomes `<strong>` wrapping an inner timer button + temp pill.
        $boldRanges = $this->extractBoldRanges($stepText);

        if ($boldRanges === []) {
            return $this->formatPlain($stepText);
        }

        $out = '';
        $pos = 0;
        foreach ($boldRanges as $br) {
            if ($br['offset'] > $pos) {
                $out .= $this->formatPlain(substr($stepText, $pos, $br['offset'] - $pos));
            }
            $rawSpan = substr($stepText, $br['offset'], $br['length']);
            $inner = substr($rawSpan, 2, strlen($rawSpan) - 4);
            $out .= '<strong class="font-semibold">'.$this->formatPlain($inner).'</strong>';
            $pos = $br['offset'] + $br['length'];
        }
        if ($pos < strlen($stepText)) {
            $out .= $this->formatPlain(substr($stepText, $pos));
        }
        return $out;
    }

    /**
     * Format a span of text with timer/temp substitutions (no bold processing).
     */
    private function formatPlain(string $text): string
    {
        $timerMatches = $this->timerExtractor->extract($text);
        $tempMatches = $this->findTempMatches($text);

        $all = [];
        foreach ($timerMatches as $tm) {
            $all[] = [
                'type' => 'timer',
                'offset' => $tm->offset,
                'length' => $tm->length,
                'data' => $tm,
            ];
        }
        foreach ($tempMatches as $tm) {
            $all[] = $tm;
        }
        usort($all, fn ($a, $b) => $a['offset'] <=> $b['offset']);

        // Drop overlapping matches; first one wins.
        $deduped = [];
        $cursor = -1;
        foreach ($all as $m) {
            if ($m['offset'] < $cursor) {
                continue;
            }
            $deduped[] = $m;
            $cursor = $m['offset'] + $m['length'];
        }

        $out = '';
        $pos = 0;
        $len = strlen($text);
        foreach ($deduped as $m) {
            if ($m['offset'] > $pos) {
                $out .= $this->renderProse(substr($text, $pos, $m['offset'] - $pos));
            }
            $matchedText = substr($text, $m['offset'], $m['length']);
            $out .= $this->renderMatch($m, $matchedText);
            $pos = $m['offset'] + $m['length'];
        }
        if ($pos < $len) {
            $out .= $this->renderProse(substr($text, $pos));
        }
        return $out;
    }

    /**
     * @return array<array{type:string,offset:int,length:int,data:array{value:int,unit:string}}>
     */
    private function findTempMatches(string $text): array
    {
        $out = [];
        if (! preg_match_all(self::TEMP_REGEX, $text, $matches, PREG_OFFSET_CAPTURE)) {
            return $out;
        }
        foreach ($matches[0] as $i => $hit) {
            [$raw, $offset] = $hit;
            $out[] = [
                'type' => 'temp',
                'offset' => $offset,
                'length' => strlen($raw),
                'data' => [
                    'value' => (int) $matches[1][$i][0],
                    'unit' => $matches[2][$i][0],
                ],
            ];
        }
        return $out;
    }

    /**
     * Find `**bold**` markdown spans. Returns ranges that cover the entire
     * `**...**` (asterisks included) so the renderer can swap them out for
     * `<strong>` tags.
     *
     * @return array<array{type:string,offset:int,length:int}>
     */
    private function extractBoldRanges(string $text): array
    {
        $out = [];
        if (! preg_match_all('/\*\*([^*]+?)\*\*/u', $text, $matches, PREG_OFFSET_CAPTURE)) {
            return $out;
        }
        foreach ($matches[0] as $hit) {
            [$raw, $offset] = $hit;
            $out[] = [
                'type' => 'bold',
                'offset' => $offset,
                'length' => strlen($raw),
            ];
        }
        return $out;
    }

    private function renderMatch(array $m, string $matchedText): string
    {
        if ($m['type'] === 'timer') {
            /** @var TimerMatch $tm */
            $tm = $m['data'];
            $lowAttr = $tm->durationLowSeconds !== null
                ? ' data-seconds-low="'.$tm->durationLowSeconds.'"'
                : '';
            $label = htmlspecialchars($tm->label, ENT_QUOTES, 'UTF-8');
            $inner = htmlspecialchars($matchedText, ENT_QUOTES, 'UTF-8');
            // The button calls `startTimer(...)` on the surrounding cookingMode
            // Alpine component. Single-quoted-arg + escaped JS-string for label
            // safety.
            return sprintf(
                '<button type="button" class="timer-btn" data-seconds="%d"%s data-label="%s" @click="startTimer(\'%s\', %d, %s)">%s</button>',
                $tm->durationSeconds,
                $lowAttr,
                $label,
                $this->jsEscape($tm->label),
                $tm->durationSeconds,
                $tm->durationLowSeconds === null ? 'null' : (string) $tm->durationLowSeconds,
                $inner,
            );
        }
        if ($m['type'] === 'temp') {
            $inner = htmlspecialchars($matchedText, ENT_QUOTES, 'UTF-8');
            return '<span class="metric metric-temp">'.$inner.'</span>';
        }
        return htmlspecialchars($matchedText, ENT_QUOTES, 'UTF-8');
    }

    private function renderProse(string $prose): string
    {
        return htmlspecialchars($prose, ENT_QUOTES, 'UTF-8');
    }

    private function jsEscape(string $s): string
    {
        // Suitable for inclusion inside a single-quoted JS argument.
        return str_replace(["\\", "'"], ['\\\\', "\\'"], $s);
    }
}
