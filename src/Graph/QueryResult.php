<?php

declare(strict_types=1);

namespace FalkorDB\Graph;

final readonly class QueryResult
{
    /**
     * @param array<int, string>|null $headers
     * @param array<int, array<string, mixed>>|null $data
     * @param array<string, int|float|string|bool> $stats
     * @param array<int, string> $metadata
     */
    public function __construct(
        public ?array $headers,
        public ?array $data,
        public array $stats,
        public array $metadata,
    ) {
    }
}
