<?php

declare(strict_types=1);

namespace FalkorDB\Connection;

use FalkorDB\Exception\CommandException;
use RuntimeException;
use Throwable;

final class ClusterConnectionAdapter implements ConnectionAdapter
{
    private const CLUSTER_SLOT_COUNT = 16384;
    private const ROUTE_KEY_MAX_ATTEMPTS = 5000;
    public function __construct(
        private readonly object $cluster,
    ) {
    }

    public function executeGraphCommand(string $command, string $graphName, array $arguments = []): mixed
    {
        try {
            return $this->cluster->rawCommand($graphName, $command, $graphName, ...$arguments);
        } catch (Throwable $exception) {
            throw new CommandException(
                "Failed to execute {$command} on cluster graph {$graphName}: {$exception->getMessage()}",
                previous: $exception
            );
        }
    }

    public function executeGlobalCommand(string $command, array $arguments = [], ?string $routeKey = null): mixed
    {
        try {
            $keyOrAddress = $routeKey ?? $this->pickNodeAddress();
            return $this->cluster->rawCommand($keyOrAddress, $command, ...$arguments);
        } catch (Throwable $exception) {
            throw new CommandException(
                "Failed to execute global cluster command {$command}: {$exception->getMessage()}",
                previous: $exception
            );
        }
    }

    public function listGraphs(): array
    {
        $masters = $this->masters();
        if ($masters === []) {
            return [];
        }

        $graphs = [];
        $primaryFailures = 0;

        foreach ($masters as $address) {
            try {
                $reply = $this->cluster->rawCommand($address, 'GRAPH.LIST');
                $this->mergeGraphNames($reply, $graphs);
            } catch (Throwable $exception) {
                $primaryFailures++;
            }
        }

        if ($primaryFailures < count($masters)) {
            $this->collectGraphsUsingSlotRoutedKeys($masters, $graphs);
        }

        if ($primaryFailures === count($masters) && $graphs === []) {
            throw new CommandException('Failed to collect GRAPH.LIST from all cluster masters');
        }

        return array_values(array_keys($graphs));
    }

    public function close(): void
    {
        if (method_exists($this->cluster, 'close')) {
            $this->cluster->close();
        }
    }

    public function mode(): ConnectionMode
    {
        return ConnectionMode::CLUSTER;
    }

    /**
     * @return array<int, array{0: string, 1: int}>
     */
    private function masters(): array
    {
        if (!method_exists($this->cluster, '_masters')) {
            return [];
        }

        $masters = $this->cluster->_masters();
        if (!is_array($masters)) {
            return [];
        }

        $normalized = [];
        foreach ($masters as $master) {
            if (!is_array($master) || count($master) < 2) {
                continue;
            }

            $normalized[] = [(string) $master[0], (int) $master[1]];
        }

        return $normalized;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function pickNodeAddress(): array
    {
        $masters = $this->masters();
        if ($masters === []) {
            throw new RuntimeException('Cluster does not expose any master nodes');
        }

        return $masters[0];
    }

    /**
     * @param array<int, array{0: string, 1: int}> $masters
     * @param array<string, bool> $graphs
     */
    private function collectGraphsUsingSlotRoutedKeys(array $masters, array &$graphs): void
    {
        $slotRanges = $this->slotRangesForMasters($masters);
        if ($slotRanges === []) {
            return;
        }

        foreach ($slotRanges as [$startSlot, $endSlot]) {
            $routeKey = $this->routeKeyForSlotRange($startSlot, $endSlot);
            if ($routeKey === null) {
                continue;
            }

            try {
                $reply = $this->cluster->rawCommand($routeKey, 'GRAPH.LIST');
                $this->mergeGraphNames($reply, $graphs);
            } catch (Throwable) {
            }
        }
    }

    /**
     * @param mixed $reply
     * @param array<string, bool> $graphs
     */
    private function mergeGraphNames(mixed $reply, array &$graphs): void
    {
        if (!is_array($reply)) {
            return;
        }

        foreach ($reply as $graph) {
            $graphs[(string) $graph] = true;
        }
    }

    /**
     * @param array<int, array{0: string, 1: int}> $masters
     * @return array<int, array{0: int, 1: int}>
     */
    private function slotRangesForMasters(array $masters): array
    {
        $slots = $this->clusterSlots();
        if ($slots === []) {
            return [];
        }

        $rangesByHostPort = [];
        $rangesByPort = [];

        foreach ($slots as [$startSlot, $endSlot, $host, $port]) {
            $hostPortKey = $this->masterKey($host, $port);

            if (!isset($rangesByHostPort[$hostPortKey])) {
                $rangesByHostPort[$hostPortKey] = [$startSlot, $endSlot];
            }

            if (!isset($rangesByPort[$port])) {
                $rangesByPort[$port] = [$startSlot, $endSlot];
            }
        }

        $ranges = [];
        foreach ($masters as [$host, $port]) {
            $hostPortKey = $this->masterKey((string) $host, (int) $port);
            $range = $rangesByHostPort[$hostPortKey] ?? $rangesByPort[(int) $port] ?? null;
            if ($range === null) {
                continue;
            }

            $ranges[$hostPortKey] = $range;
        }

        return array_values($ranges);
    }

    /**
     * @return array<int, array{0: int, 1: int, 2: string, 3: int}>
     */
    private function clusterSlots(): array
    {
        if (!method_exists($this->cluster, 'cluster')) {
            return [];
        }

        try {
            $slots = $this->cluster->cluster('__falkordb_cluster_slots__', 'SLOTS');
        } catch (Throwable) {
            return [];
        }

        if (!is_array($slots)) {
            return [];
        }

        $normalized = [];
        foreach ($slots as $slot) {
            if (!is_array($slot) || count($slot) < 3) {
                continue;
            }

            $startSlot = (int) ($slot[0] ?? -1);
            $endSlot = (int) ($slot[1] ?? -1);
            $master = $slot[2] ?? null;
            if (!is_array($master) || count($master) < 2) {
                continue;
            }

            $host = (string) $master[0];
            $port = (int) $master[1];
            if ($host === '' || $startSlot < 0 || $endSlot < $startSlot) {
                continue;
            }

            $normalized[] = [$startSlot, $endSlot, $host, $port];
        }

        return $normalized;
    }

    private function masterKey(string $host, int $port): string
    {
        return strtolower($host) . ':' . $port;
    }

    private function routeKeyForSlotRange(int $startSlot, int $endSlot): ?string
    {
        for ($attempt = 0; $attempt < self::ROUTE_KEY_MAX_ATTEMPTS; $attempt++) {
            $hashTag = "falkordb_route_{$startSlot}_{$attempt}";
            $slot = $this->slotForHashTag($hashTag);
            if ($slot < $startSlot || $slot > $endSlot) {
                continue;
            }

            return '{' . $hashTag . '}';
        }

        return null;
    }

    private function slotForHashTag(string $hashTag): int
    {
        return $this->crc16($hashTag) % self::CLUSTER_SLOT_COUNT;
    }

    private function crc16(string $value): int
    {
        $crc = 0;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $crc ^= ord($value[$i]) << 8;

            for ($bit = 0; $bit < 8; $bit++) {
                if (($crc & 0x8000) !== 0) {
                    $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
                    continue;
                }

                $crc = ($crc << 1) & 0xFFFF;
            }
        }

        return $crc;
    }
}
