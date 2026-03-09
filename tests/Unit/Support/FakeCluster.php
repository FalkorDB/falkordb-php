<?php

declare(strict_types=1);

namespace FalkorDB\Tests\Unit\Support;

use Closure;

final class FakeCluster
{
    /** @var array<int, array<int, mixed>> */
    public array $calls = [];
    public bool $closed = false;

    /** @var Closure(mixed, string, array<int, mixed>): mixed */
    private readonly Closure $responder;

    /**
     * @param array<int, array{0: string, 1: int}> $masters
     * @param callable(mixed, string, array<int, mixed>): mixed $responder
     */
    public function __construct(
        private readonly array $masters,
        callable $responder,
    ) {
        $this->responder = Closure::fromCallable($responder);
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

    public function close(): bool
    {
        $this->closed = true;
        return true;
    }
}
