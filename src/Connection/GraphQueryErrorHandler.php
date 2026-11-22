<?php

declare(strict_types=1);

namespace FalkorDB\Connection;

use FalkorDB\Exception\CommandException;

final class GraphQueryErrorHandler
{
    private const QUERY_COMMANDS = ['GRAPH.QUERY', 'GRAPH.RO_QUERY'];

    public static function isQueryCommand(string $command): bool
    {
        return in_array(strtoupper($command), self::QUERY_COMMANDS, true);
    }

    public static function clearLastError(object $connection): void
    {
        if (method_exists($connection, 'clearLastError')) {
            $connection->clearLastError();
        }
    }

    public static function falseReplyException(
        object $connection,
        string $command,
        string $context,
        mixed $reply
    ): ?CommandException {
        if ($reply !== false || !self::isQueryCommand($command) || !method_exists($connection, 'getLastError')) {
            return null;
        }

        $lastError = $connection->getLastError();
        if (!is_string($lastError) || $lastError === '') {
            return null;
        }

        return new CommandException("Failed to execute {$command} {$context}: {$lastError}");
    }
}
