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
 * A cluster client with no cluster behind it, as phpredis 6.3 has one: no
 * pipeline(), so every call goes out on its own. Reads come from a replica
 * where $failover says so (see FakeServer).
 */
final class FakeCluster extends \RedisCluster
{
    use FakeServer;

    /** How many reads went out one at a time */
    public int $direct = 0;

    public int $multis = 0;

    public function __construct(private readonly int $failover = \RedisCluster::FAILOVER_NONE)
    {
        // no seeds, no connection: everything below answers from memory
    }

    public function getOption(int $option): ?int
    {
        return \RedisCluster::OPT_SLAVE_FAILOVER === $option ? $this->failover : null;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function xrange(string $key, string $start, string $end, int $count = -1): array
    {
        return $this->range($start, $count, \RedisCluster::FAILOVER_NONE === $this->failover);
    }

    /**
     * @param array<mixed> $args
     */
    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        return $this->evaluate($script, $args);
    }

    /**
     * Holds no script: every one is sent again as a body.
     *
     * @param array<mixed> $args
     */
    public function evalsha(string $sha1, array $args = [], int $num_keys = 0): mixed
    {
        return false;
    }

    /**
     * @param array<mixed> $keys
     *
     * @return array<string, string|false>
     */
    public function hmget(string $key, array $keys): array
    {
        ++$this->direct;

        return $this->row($key, $keys);
    }

    /**
     * @param array<mixed>|string $key
     */
    public function unlink(array|string $key, string ...$other_keys): int
    {
        return $this->forget($key, ...$other_keys);
    }

    /**
     * A raw command goes where the last read of its slot went: on a cluster
     * reading from replicas, a replica, which refuses a write (phpredis 6.3).
     *
     * @param array<mixed>|string $key_or_address
     */
    public function rawcommand(array|string $key_or_address, string $command, mixed ...$args): mixed
    {
        if (\RedisCluster::FAILOVER_NONE !== $this->failover) {
            throw new \RedisClusterException('Unable to send command to the specified node');
        }

        return 1;
    }

    /**
     * @param array<mixed>      $fields
     * @param array<mixed>|null $expiry
     */
    public function hsetex(string $key, array $fields, ?array $expiry = null): int
    {
        $this->items[$key] = [];

        foreach ($fields as $field => $value) {
            $this->items[$key][(string) $field] = \is_scalar($value) ? (string) $value : '';
        }

        return 1;
    }

    public function expire(string $key, int $timeout, ?string $mode = null): bool
    {
        return true;
    }

    /**
     * phpredis 6.3 refuses a pipeline on a cluster; counted, and refused.
     */
    public function multi(int $value = \Redis::MULTI): \RedisCluster|bool
    {
        ++$this->multis;

        return false;
    }
}
