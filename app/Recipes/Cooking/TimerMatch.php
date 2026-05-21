<?php

declare(strict_types=1);

namespace App\Recipes\Cooking;

/**
 * A single timer phrase detected inside method-step prose.
 *
 * Offsets are byte-offsets into the input string. For ranges, $durationSeconds
 * is the UPPER bound (cooks rather overshoot than undershoot, per the brief);
 * $durationLowSeconds carries the lower bound for the "lower bound reached"
 * notification.
 */
final class TimerMatch
{
    public function __construct(
        public readonly string $raw,
        public readonly int $offset,
        public readonly int $length,
        public readonly int $durationSeconds,
        public readonly ?int $durationLowSeconds,
        public readonly string $label,
    ) {}
}
