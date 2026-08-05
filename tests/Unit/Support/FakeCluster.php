<?php

declare(strict_types=1);

namespace FalkorDB\Tests\Unit\Support;

use Closure;

final class FakeCluster
{
    /** @var array<int, array<int, mixed>> */
    public array $calls = [];
    /** @var array<int, array<int, mixed>> */
    public array $clusterCalls = [];
    public bool $closed = false;

    /** @var Closure(mixed, string, array<int, mixed>): mixed */
    private readonly Closure $responder;
    /** @var null|Closure(mixed, string, array<int, mixed>): mixed */
    private readonly ?Closure $clusterResponder;

    /**
     * @param array<int, array{0: string, 1: int}> $masters
     * @param callable(mixed, string, array<int, mixed>): mixed $responder
     * @param null|callable(mixed, string, array<int, mixed>): mixed $clusterResponder
     */
    public function __construct(
        private readonly array $masters,
        callable $responder,
        ?callable $clusterResponder = null,
    ) {
        $this->responder = Closure::fromCallable($responder);
        $this->clusterResponder = $clusterResponder !== null
            ? Closure::fromCallable($clusterResponder)
            : null;
    }

    /**
     * @param mixed $keyOrAddress
     */
    public function rawCommand(mixed $keyOrAddress, string $command, mixed ...$args): mixed
    {
        $this->calls[] = [$keyOrAddress, $command, ...$args];
        return ($this->responder)($keyOrAddress, $command, $args);
    }

    /**
     * @return array<int, array{0: string, 1: int}>
     */
    public function _masters(): array
    {
        return $this->masters;
    }

    /**
     * @param mixed $keyOrAddress
     */
    public function cluster(mixed $keyOrAddress, string $command, mixed ...$args): mixed
    {
        $this->clusterCalls[] = [$keyOrAddress, $command, ...$args];
        if ($this->clusterResponder === null) {
            return [];
        }

        return ($this->clusterResponder)($keyOrAddress, $command, $args);
    }

    public function close(): bool
    {
        $this->closed = true;
        return true;
    }
}
