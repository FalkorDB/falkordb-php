<?php

declare(strict_types=1);

namespace FalkorDB\Value;

final readonly class NodeValue
{
    /**
     * @param array<int, string> $labels
     * @param array<string, mixed> $properties
     */
    public function __construct(
        public int $id,
        public array $labels,
        public array $properties,
    ) {
    }
}
