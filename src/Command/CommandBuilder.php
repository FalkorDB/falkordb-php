<?php

declare(strict_types=1);

namespace FalkorDB\Command;

final class CommandBuilder
{
    /**
     * @param array<string, mixed>|int|null $options
     * @return array<int, string>
     */
    public static function queryArguments(string $query, array|int|null $options = null, bool $compact = true): array
    {
        $args = [];
        $timeout = null;
        $queryArgument = $query;

        if (is_int($options)) {
            $timeout = $options;
        } elseif (is_array($options)) {
            $params = isset($options['params']) && is_array($options['params']) ? $options['params'] : null;
            if ($params !== null && $params !== []) {
                $queryArgument = 'CYPHER ' . QueryParameterSerializer::serialize($params) . " {$query}";
            }

            if (isset($options['TIMEOUT'])) {
                $timeout = (int) $options['TIMEOUT'];
            } elseif (isset($options['timeout'])) {
                $timeout = (int) $options['timeout'];
            }
        }

        $args[] = $queryArgument;

        if ($timeout !== null) {
            $args[] = 'TIMEOUT';
            $args[] = (string) $timeout;
        }

        if ($compact) {
            $args[] = '--compact';
        }

        return $args;
    }

    /**
     * @param array<int, string> $properties
     * @return array<int, string>
     */
    public static function constraintArguments(
        string $action,
        string $constraintType,
        string $entityType,
        string $label,
        array $properties
    ): array {
        return [
            $action,
            $constraintType,
            $entityType,
            $label,
            'PROPERTIES',
            (string) count($properties),
            ...$properties,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function memoryUsageArguments(?int $samples = null): array
    {
        $args = ['USAGE'];
        if ($samples !== null) {
            $args[] = (string) $samples;
        }
        return $args;
    }

    /**
     * @return array<int, string>
     */
    public static function udfLoadArguments(string $libraryName, string $script, bool $replace = false): array
    {
        $args = ['LOAD'];
        if ($replace) {
            $args[] = 'REPLACE';
        }
        $args[] = $libraryName;
        $args[] = $script;
        return $args;
    }

    /**
     * @return array<int, string>
     */
    public static function udfListArguments(?string $libraryName = null, bool $withCode = false): array
    {
        $args = ['LIST'];
        if ($libraryName !== null) {
            $args[] = $libraryName;
        }
        if ($withCode) {
            $args[] = 'WITHCODE';
        }
        return $args;
    }

    /**
     * Build trailing arguments for GRAPH.LOAD (enterprise offloading).
     *
     * @return array<int, string>
     */
    public static function loadArguments(bool $force = false): array
    {
        return $force ? ['FORCE'] : [];
    }

    /**
     * Parse GRAPH.STUBINFO flat field/value reply into an associative array.
     *
     * @param array<int, mixed> $reply
     * @return array{path: string, size: int, mtime: int, atime: int, ctime: int}
     */
    public static function parseStubInfoReply(array $reply): array
    {
        $info = [
            'path' => '',
            'size' => 0,
            'mtime' => 0,
            'atime' => 0,
            'ctime' => 0,
        ];

        $count = count($reply);
        for ($i = 0; $i + 1 < $count; $i += 2) {
            $field = strtolower((string) $reply[$i]);
            $value = $reply[$i + 1];

            if ($field === 'path') {
                $info['path'] = (string) $value;
                continue;
            }

            if (in_array($field, ['size', 'mtime', 'atime', 'ctime'], true)) {
                $info[$field] = (int) $value;
            }
        }

        return $info;
    }
}
