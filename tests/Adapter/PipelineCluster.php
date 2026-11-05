<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests\Adapter;

/**
 * A cluster client as phpredis will have one once cluster pipelines ship
 * (phpredis#2798): it has pipeline(), and multi(Redis::PIPELINE) queues the
 * calls across slots until exec() sends them together.
 */
final class PipelineCluster extends \RedisCluster
{
    use FakeServer;

    public int $direct = 0;
    public int $execs = 0;

    /** @var list<mixed>|null the replies queued while a pipeline is open */
    private ?array $queued = null;

    public function __construct()
    {
        // no seeds, no connection: everything below answers from memory
    }

    public function pipeline(): static
    {
        return $this->multi(\Redis::PIPELINE);
    }

    public function multi(int $value = \Redis::MULTI): static
    {
        $this->queued = [];

        return $this;
    }

    /**
     * @return list<mixed>
     */
    public function exec(): array
    {
        ++$this->execs;
        $replies = $this->queued ?? [];
        $this->queued = null;

        return $replies;
    }

    public function discard(): bool
    {
        $this->queued = null;

        return true;
    }

    public function getOption(int $option): ?int
    {
        return \RedisCluster::OPT_SLAVE_FAILOVER === $option ? \RedisCluster::FAILOVER_NONE : null;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function xrange(string $key, string $start, string $end, int $count = -1): array
    {
        return $this->range($start, $count, true);
    }

    /**
     * @param array<mixed> $keys
     *
     * @return static|array<string, string|false>
     */
    public function hmget(string $key, array $keys): static|array
    {
        $row = $this->row($key, $keys);

        if (null !== $this->queued) {
            $this->queued[] = $row;

            return $this;
        }

        ++$this->direct;

        return $row;
    }

    /**
     * @param array<mixed>|string $key
     */
    public function unlink(array|string $key, string ...$other_keys): int
    {
        return $this->forget($key, ...$other_keys);
    }
}
