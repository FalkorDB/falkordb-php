<?php

declare(strict_types=1);

namespace FalkorDB\Value;

final readonly class PointValue
{
    public function __construct(
        public float $latitude,
        public float $longitude,
    ) {
    }
}
