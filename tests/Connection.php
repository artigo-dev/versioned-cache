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
     * Skips the calling test when nothing usable answers. $prefix is set as
     * the client's own key prefix (OPT_PREFIX), which every key it sends
     * carries.
     */
    public static function open(?string $dsn = null, string $prefix = ''): \Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster
    {
        $dsn ??= self::dsn();
        $connection = null;

        if (self::isRelay() && !class_exists(\Relay\Relay::class)) {
            Assert::markTestSkipped('REDIS_CLIENT=relay, but ext-relay is not loaded.');
        }

        // told the class is Relay\Relay, Symfony opens one node of a cluster
        $relayClass = 1 === preg_match('/[?&](?:redis_)?cluster=(?:1|true|on|yes)(?:&|$)/i', $dsn) ? \Relay\Cluster::class : \Relay\Relay::class;

        try {
            $connection = RedisAdapter::createConnection($dsn, self::isRelay() ? ['class' => $relayClass] : []);
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

        if ('' !== $prefix) {
            // Relay's cluster client takes the option by its single-node name
            $option = $connection instanceof \Relay\Relay || $connection instanceof \Relay\Cluster ? \Relay\Relay::OPT_PREFIX : \Redis::OPT_PREFIX;

            Assert::assertTrue($connection->setOption($option, $prefix), 'the client takes a key prefix');
        }

        return $connection;
    }

    /**
     * The same, reading from the primaries only: what a test checks the
     * server holds must not be a replica's lagging copy of it.
     */
    public static function openPrimaries(string $prefix = ''): \Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster
    {
        return self::open((string) preg_replace('/&failover=[a-z_]+/', '', self::dsn()), $prefix);
    }

    /**
     * A RedisArray of two nodes that are one server under two host names, the
     * second switched to another database: a command sent to the wrong node
     * lands where the right one does not see it. Skips where the DSN is not a
     * plain single node, or the host has no second name.
     */
    public static function openArray(string $prefix = ''): \RedisArray
    {
        $parts = parse_url(self::dsn());

        if (!\is_array($parts) || 'redis' !== ($parts['scheme'] ?? null) || !isset($parts['host']) || isset($parts['query']) || isset($parts['pass'])) {
            Assert::markTestSkipped(\sprintf('An array needs a plain single-node DSN, "%s" given.', self::dsn()));
        }

        $host = $parts['host'];
        $port = $parts['port'] ?? 6379;
        $database = (int) trim($parts['path'] ?? '', '/');

        // another name for the same server: its address, or a name for loopback
        $alias = gethostbyname($host);

        if ($alias === $host) {
            $alias = '127.0.0.1' === $host ? 'localhost' : null;
        }

        if (null === $alias) {
            Assert::markTestSkipped(\sprintf('"%s" has no second name to stand for a second node.', $host));
        }

        $hosts = [$host.':'.$port, $alias.':'.$port];

        try {
            // a distribution that spreads keys the same way everywhere: the
            // default of a Windows build of phpredis puts every key on the first
            $array = new \RedisArray($hosts, [
                'lazy_connect' => false,
                'connect_timeout' => 1.0,
                'distributor' => static fn (string $key): int => crc32($key) % 2,
            ]);
            $first = $array->_instance($hosts[0]);
            $second = $array->_instance($hosts[1]);

            if (!$first instanceof \Redis || !$second instanceof \Redis || !$first->select($database) || !$second->select(9 === $database ? 10 : 9)) {
                Assert::markTestSkipped('The array did not connect both nodes.');
            }
        } catch (\RedisException $e) {
            Assert::markTestSkipped(\sprintf('Redis is not reachable as an array of "%s": %s', implode('", "', $hosts), $e->getMessage()));
        }

        if ('' !== $prefix) {
            // set on every node; the array answers once per node
            $array->setOption(\Redis::OPT_PREFIX, $prefix);

            foreach (self::nodesOf($array) as $node) {
                Assert::assertSame($prefix, $node->getOption(\Redis::OPT_PREFIX), 'every node takes the key prefix');
            }
        }

        return $array;
    }

    /**
     * The nodes of an array, by host.
     *
     * @return array<string, \Redis>
     */
    public static function nodesOf(\RedisArray $array): array
    {
        $nodes = [];
        $hosts = $array->_hosts();

        foreach (\is_array($hosts) ? $hosts : [] as $host) {
            $node = \is_string($host) ? $array->_instance($host) : null;

            if ($node instanceof \Redis) {
                $nodes[$host] = $node;
            }
        }

        return $nodes;
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
