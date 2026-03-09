<?php

declare(strict_types=1);

namespace FalkorDB\Value;

final readonly class EdgeValue
{
    /**
     * @param array<string, mixed> $properties
     */
    public function __construct(
        public int $id,
        public string $relationshipType,
        public int $sourceId,
        public int $destinationId,
        public array $properties,
    ) {
    }
}
