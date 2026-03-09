<?php

declare(strict_types=1);

namespace FalkorDB\Command;

use FalkorDB\Exception\InvalidArgumentException;

final class QueryParameterSerializer
{
    /**
     * @param array<string, mixed> $params
     */
    public static function serialize(array $params): string
    {
        $parts = [];

        foreach ($params as $key => $value) {
            $parts[] = "{$key}=" . self::value($value);
        }

        return implode(' ', $parts);
    }

    private static function value(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_string($value)) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(',', array_map(self::value(...), $value)) . ']';
            }

            $parts = [];
            foreach ($value as $key => $innerValue) {
                $parts[] = "{$key}:" . self::value($innerValue);
            }
            return '{' . implode(',', $parts) . '}';
        }

        throw new InvalidArgumentException('Unsupported query parameter type: ' . gettype($value));
    }
}
