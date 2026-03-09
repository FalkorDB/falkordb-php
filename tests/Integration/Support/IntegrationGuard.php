<?php

declare(strict_types=1);

namespace FalkorDB\Tests\Integration\Support;

trait IntegrationGuard
{
    private function isEnabled(string $flagName): bool
    {
        $value = strtolower((string) getenv($flagName));
        return in_array($value, ['1', 'true', 'yes'], true);
    }

    private function shouldRunBaseIntegration(): bool
    {
        return $this->isEnabled('FALKORDB_RUN_INTEGRATION');
    }

    private function shouldRunClusterIntegration(): bool
    {
        return $this->shouldRunBaseIntegration() && $this->isEnabled('FALKORDB_RUN_CLUSTER_INTEGRATION');
    }

    private function shouldRunSentinelIntegration(): bool
    {
        return $this->shouldRunBaseIntegration() && $this->isEnabled('FALKORDB_RUN_SENTINEL_INTEGRATION');
    }
}
