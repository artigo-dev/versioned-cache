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
 * A single node with nothing behind it: what it answers is what the primary
 * holds, there being nothing else to ask (see FakeServer).
 */
final class FakeRedis extends \Redis
{
    use FakeServer;

    /**
     * @return array<string, array<string, string>>
     */
    public function xrange(string $key, string $start, string $end, int $count = -1): array
    {
        // $replicaHolds stands for a stream that lost entries: a single node
        // has no replica to lag
        return $this->range($start, $count, false);
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
     * @param array<mixed> $fields
     *
     * @return array<string, string|false>
     */
    public function hMget(string $key, array $fields): array
    {
        return $this->row($key, $fields);
    }

    /**
     * @param array<mixed>|string $key
     */
    public function unlink(array|string $key, string ...$other_keys): int
    {
        return $this->forget($key, ...$other_keys);
    }

    /**
     * No pipeline: the adapter sends one call at a time.
     */
    public function multi(int $value = \Redis::MULTI): \Redis|bool
    {
        return false;
    }
}
