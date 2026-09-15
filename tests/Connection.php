<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests;

use PHPUnit\Framework\Assert;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/**
 * The connection every test in this suite runs against, so that pointing the
 * suite somewhere else is one environment variable rather than an edit.
 *
 *   REDIS_DSN     where to connect; a cluster DSN works as well as a single node
 *   REDIS_CLIENT  "relay" to run everything through Relay instead of ext-redis
 *
 * The adapter should not be able to tell any of those apart, which is the
 * whole reason the suite can be pointed at them.
 */
final class Connection
{
    public static function dsn(): string
    {
        return getenv('REDIS_DSN') ?: 'redis://127.0.0.1:6379';
    }

    public static function isRelay(): bool
    {
        return 'relay' === getenv('REDIS_CLIENT');
    }

    /**
     * Skips the calling test when nothing usable answers.
     */
    public static function open(): \Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster
    {
        $dsn = self::dsn();
        $connection = null;

        if (self::isRelay() && !class_exists(\Relay\Relay::class)) {
            Assert::markTestSkipped('REDIS_CLIENT=relay, but ext-relay is not loaded.');
        }

        try {
            $connection = RedisAdapter::createConnection($dsn, self::isRelay() ? ['class' => \Relay\Relay::class] : []);
        } catch (\Throwable $e) {
            Assert::markTestSkipped(\sprintf('Redis is not reachable at "%s": %s', $dsn, $e->getMessage()));
        }

        if (!$connection instanceof \Redis
            && !$connection instanceof \RedisCluster
            && !$connection instanceof \Relay\Relay
            && !$connection instanceof \Relay\Cluster
        ) {
            Assert::markTestSkipped(\sprintf('These tests need an ext-redis or Relay connection, "%s" given.', get_debug_type($connection)));
        }

        return $connection;
    }

    /**
     * The same, for the tests that only make sense against one node: the
     * MemoLock ones open a second connection of their own and block on it.
     */
    public static function openSingleNode(): \Redis|\Relay\Relay
    {
        $connection = self::open();

        if (!$connection instanceof \Redis && !$connection instanceof \Relay\Relay) {
            Assert::markTestSkipped(\sprintf('These tests need a single node, "%s" given.', get_debug_type($connection)));
        }

        try {
            $connection->ping();
        } catch (\Throwable $e) {
            Assert::markTestSkipped(\sprintf('Redis did not answer at "%s": %s', self::dsn(), $e->getMessage()));
        }

        return $connection;
    }

    /**
     * A single node again, but as a given \Redis subclass - for the tests that
     * need to watch, or break, what the adapter says to the server.
     *
     * Only a plain redis://host[:port][/db] DSN can be turned into one; every
     * other shape skips the test.
     *
     * @template T of \Redis
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public static function openSingleNodeAs(string $class): \Redis
    {
        if (self::isRelay()) {
            Assert::markTestSkipped('These tests instrument ext-redis and cannot run through Relay.');
        }

        $parts = parse_url(self::dsn());

        if (!\is_array($parts) || 'redis' !== ($parts['scheme'] ?? null) || !isset($parts['host']) || str_contains($parts['query'] ?? '', 'redis_cluster')) {
            Assert::markTestSkipped(\sprintf('These tests need a plain single-node DSN, "%s" given.', self::dsn()));
        }

        $redis = new $class();

        try {
            $redis->connect($parts['host'], $parts['port'] ?? 6379, 1.0);

            if (isset($parts['pass'])) {
                $redis->auth(isset($parts['user']) ? [$parts['user'], $parts['pass']] : $parts['pass']);
            }

            if ('' !== $database = trim($parts['path'] ?? '', '/')) {
                $redis->select((int) $database);
            }
        } catch (\Throwable $e) {
            Assert::markTestSkipped(\sprintf('Redis is not reachable at "%s": %s', self::dsn(), $e->getMessage()));
        }

        return $redis;
    }

    /**
     * Deletes the keys a test wrote, wherever they live. A cluster scans one
     * master at a time, so asking the connection generally would find only a
     * share of them.
     */
    public static function sweep(\Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $connection, string $pattern): void
    {
        if ($connection instanceof \RedisCluster || $connection instanceof \Relay\Cluster) {
            foreach ($connection->_masters() as $master) {
                if (!\is_array($master) && !\is_string($master)) {
                    continue;
                }

                $cursor = null;

                do {
                    $keys = $connection->scan($cursor, $master, $pattern, 500);
                    $keys = \is_array($keys) ? array_filter($keys, \is_string(...)) : [];

                    if ([] !== $keys) {
                        $connection->del(...$keys);
                    }
                } while ($cursor > 0);
            }

            return;
        }

        $cursor = null;

        do {
            $keys = $connection->scan($cursor, $pattern, 500);
            $keys = \is_array($keys) ? array_filter($keys, \is_string(...)) : [];

            if ([] !== $keys) {
                $connection->del(...$keys);
            }
        } while ($cursor > 0);
    }
}
