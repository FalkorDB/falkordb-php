<?php

declare(strict_types=1);

namespace FalkorDB\Value;

final readonly class DurationValue
{
    public function __construct(
        public int $totalSeconds,
    ) {
    }
}
