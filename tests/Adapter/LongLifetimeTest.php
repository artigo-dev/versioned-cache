<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests\Adapter;

use Artigo\Cache\Adapter\VersionedRedisTagAwareAdapter;
use Artigo\Cache\Tests\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Lifetimes and a guard window of a month, whose milliseconds a 32-bit
 * integer does not hold, through whichever way the connection's writes go
 * out: raw commands, which carry their numbers as strings, or the typed calls
 * a cluster reading from replicas or pipelining is sent, whose integers
 * phpredis passes through a C long. Every TTL must arrive as it was meant.
 */
final class LongLifetimeTest extends TestCase
{
    private const DAY = 86_400;

    /** The guard window: 2 678 400 000 ms */
    private const WINDOW = 31 * self::DAY;

    private string $namespace;

    private \Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis;
    private \Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $raw;

    protected function setUp(): void
    {
        $this->namespace = 'long'.bin2hex(random_bytes(3));
        $this->redis = Connection::open();
        $this->raw = Connection::openPrimaries();
    }

    protected function tearDown(): void
    {
        if (isset($this->raw)) {
            Connection::sweep($this->raw, $this->namespace.'*');
        }
    }

    public function testAMonthLongTtlSurvivesEveryWrite(): void
    {
        // the typed HPEXPIRE that drops an outdated item takes milliseconds,
        // which a 32-bit long wraps
        if ('Windows' === \PHP_OS_FAMILY && ($this->redis instanceof \RedisCluster && \RedisCluster::FAILOVER_NONE !== $this->redis->getOption(\RedisCluster::OPT_SLAVE_FAILOVER))) {
            self::markTestSkipped('On Windows, phpredis passes the typed HPEXPIRE of a cluster reading from replicas through a 32-bit long: a guard window above 24 days wraps.');
        }

        // a plain write living longer than the window: the fields and the
        // epoch in one HSETEX, then the key's TTL
        $this->plainWrite('long', 35 * self::DAY);

        self::assertTtl(35 * self::DAY, $this->pttl('long'), 'the key');
        self::assertTtl(35 * self::DAY, $this->hpttl('long', 'v'), 'the value');
        self::assertTtl(35 * self::DAY, $this->hpttl('long', 'e'), 'the epoch');

        // read once it is outdated: the data goes, and HPEXPIRE ... LT brings
        // the epoch down to the window
        self::assertTrue($this->pool()->invalidateTags(['t']));
        self::assertFalse($this->pool()->getItem('long')->isHit());

        self::assertSame(-2, $this->hpttl('long', 'v'), 'the value is gone');
        self::assertTtl(self::WINDOW, $this->hpttl('long', 'e'), 'the epoch is kept for the window');

        // a plain write living shorter than the window: the data with its own
        // TTL, the epoch and the key with the window's
        $this->plainWrite('short', 25 * self::DAY);

        self::assertTtl(25 * self::DAY, $this->hpttl('short', 'v'), 'the value');
        self::assertTtl(self::WINDOW, $this->hpttl('short', 'e'), 'the epoch');
        self::assertTtl(self::WINDOW, $this->pttl('short'), 'the key');

        // a delete: the tombstone's epoch for the window, and the key's TTL
        // raised to it
        self::assertTrue($this->pool()->deleteItem('short'));

        self::assertSame(-2, $this->hpttl('short', 'v'), 'the value is gone');
        self::assertTtl(self::WINDOW, $this->hpttl('short', 'e'), 'the tombstone');
        self::assertTtl(self::WINDOW, $this->pttl('short'), 'the key');
    }

    private function pool(): VersionedRedisTagAwareAdapter
    {
        return new VersionedRedisTagAwareAdapter($this->redis, $this->namespace, rulesRetentionSeconds: 40 * self::DAY, rulesCacheMs: 0, rulesCache: false, guardWindowSeconds: self::WINDOW);
    }

    /**
     * Saves $key through a pool that did not read it, so the write is a plain
     * one.
     */
    private function plainWrite(string $key, int $lifetime): void
    {
        $item = $this->pool()->getItem($key);

        self::assertTrue($this->pool()->save($item->set('v')->expiresAfter($lifetime)->tag('t')));
    }

    /**
     * $ttl seconds, as a TTL in milliseconds that the test's own run time may
     * have shortened by a minute at most.
     */
    private static function assertTtl(int $ttl, int $actualMs, string $what): void
    {
        self::assertGreaterThan($ttl * 1000 - 60_000, $actualMs, $what);
        self::assertLessThanOrEqual($ttl * 1000, $actualMs, $what);
    }

    private function pttl(string $key): int
    {
        return self::int($this->command('PTTL', $key));
    }

    /**
     * HPTTL of one field: -2 where there is no such field.
     */
    private function hpttl(string $key, string $field): int
    {
        $answer = $this->command('HPTTL', $key, 'FIELDS', '1', $field);

        return self::int(\is_array($answer) ? $answer[0] ?? null : $answer);
    }

    /**
     * A command about one of this test's items, on a primary, as the server
     * names the key.
     */
    private function command(string $command, string $id, string ...$arguments): mixed
    {
        $key = $this->namespace.':'.$id;

        return $this->raw instanceof \Redis || $this->raw instanceof \Relay\Relay
            ? $this->raw->rawCommand($command, $key, ...$arguments)
            : $this->raw->rawCommand($key, $command, $key, ...$arguments);
    }

    private static function int(mixed $value): int
    {
        self::assertIsInt($value);

        return $value;
    }
}
