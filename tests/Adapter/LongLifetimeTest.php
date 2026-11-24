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
 * a phpredis cluster is sent, whose integers phpredis passes through a C
 * long, 32 bits wide on Windows, and which therefore carry seconds. Every TTL
 * must arrive as it was meant.
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
    }

    protected function tearDown(): void
    {
        if (isset($this->raw)) {
            Connection::sweep($this->raw, $this->namespace.'*');
        }
    }

    /**
     * The typed drop of an outdated item, against a stand-in that reads from
     * replicas, so it is written through typed calls on every platform: the
     * window goes out as seconds, all 2 678 400 of them.
     */
    public function testTheTypedDropOfAnOutdatedItemSendsTheWindowInSeconds(): void
    {
        $cluster = new FakeCluster(\RedisCluster::FAILOVER_DISTRIBUTE_SLAVES);
        $pool = new VersionedRedisTagAwareAdapter($cluster, $this->namespace, rulesRetentionSeconds: 40 * self::DAY, rulesCacheMs: 0, rulesCache: false, guardWindowSeconds: self::WINDOW);

        self::assertTrue($pool->save($pool->getItem('k')->set('v')->tag('t')));
        self::assertTrue($pool->invalidateTags(['t']));
        self::assertFalse($pool->getItem('k')->isHit());

        self::assertSame([['hexpire', self::WINDOW, ['e'], 'LT']], $cluster->fieldExpiries, 'HEXPIRE with the window in seconds, only if lower (LT)');
        self::assertSame(['e'], array_keys($cluster->items[$this->namespace.':k'] ?? []), 'the data is gone and the epoch kept');
    }

    public function testAMonthLongTtlSurvivesEveryWrite(): void
    {
        $this->redis = Connection::open();
        $this->raw = Connection::openPrimaries();

        // a plain write living longer than the window: the fields and the
        // epoch in one HSETEX, then the key's TTL
        $this->plainWrite('long', 35 * self::DAY);

        self::assertTtl(35 * self::DAY, $this->pttl('long'), 'the key');
        self::assertTtl(35 * self::DAY, $this->hpttl('long', 'v'), 'the value');
        self::assertTtl(35 * self::DAY, $this->hpttl('long', 'e'), 'the epoch');

        // read once it is outdated: the data goes, and HEXPIRE ... LT (raw:
        // HPEXPIRE) brings the epoch down to the window
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
