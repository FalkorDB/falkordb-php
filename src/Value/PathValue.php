<?php

declare(strict_types=1);

namespace FalkorDB\Value;

final readonly class PathValue
{
    /**
     * @param array<int, NodeValue> $nodes
     * @param array<int, EdgeValue> $edges
     */
    public function __construct(
        public array $nodes,
        public array $edges,
    ) {
    }
}
