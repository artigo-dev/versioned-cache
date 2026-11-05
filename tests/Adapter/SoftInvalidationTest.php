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
 * What a soft tag invalidation leaves: a miss for every read, and the value
 * kept for the grace, for whoever may serve it stale. What stays hard: a hard
 * rule, a delete, a clear.
 */
final class SoftInvalidationTest extends TestCase
{
    private \Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis;

    private string $namespace;

    protected function setUp(): void
    {
        $this->redis = Connection::open();
        // the server's clock is kept per pool and process: a pool of its own
        $this->namespace = 'soft'.bin2hex(random_bytes(3));
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            Connection::sweep($this->redis, $this->namespace.'*');
        }
    }

    public function testAnItemInsideTheGraceIsKeptAndAMiss(): void
    {
        $this->write('k', 'before', 'menu');
        self::assertTrue($this->pool()->invalidateTags(['menu']));

        $reader = $this->pool();
        self::assertFalse($reader->getItem('k')->isHit(), 'a strict read misses it');

        $aged = $reader->takeAgedItem('k');
        self::assertNotNull($aged, 'and it is kept');
        self::assertSame('before', $aged['value']);
        self::assertSame(['menu'], $aged['tags']);
        self::assertGreaterThanOrEqual(0, $aged['ageMs']);
        self::assertLessThan(60_000, $aged['ageMs']);
    }

    public function testAnItemPastTheGraceIsDropped(): void
    {
        $this->write('k', 'before', 'menu');
        self::assertTrue($this->pool()->invalidateTags(['menu']));
        $this->waitPast(1.0);

        $reader = $this->pool(graceSeconds: 1);
        self::assertFalse($reader->getItem('k')->isHit());
        self::assertNull($reader->takeAgedItem('k'), 'past the grace: dropped as before');
        self::assertSame(0, $this->exists('k'), 'and its value gone');
    }

    public function testWithoutAGraceNothingIsKept(): void
    {
        $this->write('k', 'before', 'menu');
        self::assertTrue($this->pool()->invalidateTags(['menu']));

        $reader = $this->pool(graceSeconds: 0);
        self::assertFalse($reader->getItem('k')->isHit());
        self::assertNull($reader->takeAgedItem('k'));
    }

    public function testTakeAgedItemHandsOutTheValueOnce(): void
    {
        $this->write('k', 'before', 'menu');
        self::assertTrue($this->pool()->invalidateTags(['menu']));

        $reader = $this->pool();
        $reader->getItem('k');

        self::assertNotNull($reader->takeAgedItem('k'));
        self::assertNull($reader->takeAgedItem('k'), 'once');
        self::assertNull($this->pool()->takeAgedItem('k'), 'and only to the pool that read it');
    }

    public function testAHardInvalidationAfterASoftOneReachesTheSoftValue(): void
    {
        $this->write('k', 'before', 'menu');
        self::assertTrue($this->pool()->invalidateTags(['menu']));
        self::assertTrue($this->pool()->invalidateTags(['menu'], hard: true));

        $reader = $this->pool();
        self::assertFalse($reader->getItem('k')->isHit());
        self::assertNull($reader->takeAgedItem('k'));
    }

    public function testASoftInvalidationAfterAHardOneStaysHard(): void
    {
        $this->write('k', 'before', 'menu');
        self::assertTrue($this->pool()->invalidateTags(['menu'], hard: true));
        self::assertTrue($this->pool()->invalidateTags(['menu']));

        $reader = $this->pool();
        self::assertFalse($reader->getItem('k')->isHit());
        self::assertNull($reader->takeAgedItem('k'), 'the hard rule it has not seen still stands');
    }

    public function testATagInvalidationStaysSoftByDefault(): void
    {
        $this->write('k', 'before', 'menu');
        self::assertTrue($this->pool()->invalidateTags(['menu']));

        $reader = $this->pool();
        $reader->getItem('k');

        self::assertNotNull($reader->takeAgedItem('k'));
    }

    public function testDeleteAndClearStayHard(): void
    {
        $this->write('deleted', 'before', 'menu');
        $this->write('cleared', 'before', 'menu');

        // a soft rule reaches both; then the hard ones
        self::assertTrue($this->pool()->invalidateTags(['menu']));
        self::assertTrue($this->pool()->deleteItem('deleted'));
        self::assertTrue($this->pool()->clear('cleared'));

        $reader = $this->pool();
        $reader->getItem('deleted');
        $reader->getItem('cleared');

        self::assertNull($reader->takeAgedItem('deleted'));
        self::assertNull($reader->takeAgedItem('cleared'), 'a clear is a prefix rule, and a prefix rule is hard');
    }

    public function testTheServerClockIsReadOnceAndUsedAsAnOffset(): void
    {
        $redis = Connection::openSingleNodeAs(SpyRedis::class);
        $this->write('a', 'before', 'menu');
        $this->write('b', 'before', 'menu');
        self::assertTrue($this->pool()->invalidateTags(['menu']));

        foreach (['a', 'b', 'a'] as $key) {
            $reader = new VersionedRedisTagAwareAdapter($redis, $this->namespace, rulesCacheMs: 0, rulesCache: false, softGraceSeconds: 60);
            $reader->getItem($key);
            self::assertNotNull($reader->takeAgedItem($key));
        }

        self::assertSame(1, $redis->times, 'one TIME for every read of the process');
    }

    public function testARecomputationAcrossASoftInvalidationNeverReadsFresh(): void
    {
        $a = $this->pool();
        $item = $a->getItem('k');

        // the tag falls softly while the value is computed
        self::assertTrue($this->pool()->invalidateTags(['menu']));
        self::assertTrue($a->save($item->set('computed across it')->tag('menu')), 'it lands, under its read');

        $reader = $this->pool();
        self::assertFalse($reader->getItem('k')->isHit(), 'never fresh');

        $aged = $reader->takeAgedItem('k');
        self::assertNotNull($aged, 'only ever aged');
        self::assertSame('computed across it', $aged['value']);
    }

    private function pool(int $graceSeconds = 60): VersionedRedisTagAwareAdapter
    {
        return new VersionedRedisTagAwareAdapter($this->redis, $this->namespace, rulesCacheMs: 0, rulesCache: false, softGraceSeconds: $graceSeconds);
    }

    private function write(string $key, string $value, string $tag): void
    {
        $pool = $this->pool();
        self::assertTrue($pool->save($pool->getItem($key)->set($value)->tag($tag)));
    }

    private function exists(string $key): int
    {
        $exists = $this->redis->hExists($this->namespace.':'.$key, 'v');

        return true === $exists ? 1 : 0;
    }

    /**
     * Sleeps past a deadline as the server's clock counts it.
     */
    private function waitPast(float $seconds): void
    {
        $time = fn (): float => self::seconds($this->redis instanceof \RedisCluster || $this->redis instanceof \Relay\Cluster ? $this->redis->time($this->namespace) : $this->redis->time());
        $deadline = $time() + $seconds;

        do {
            usleep(50_000);
        } while ($time() < $deadline);
    }

    private static function seconds(mixed $time): float
    {
        $time = \is_array($time) ? array_values($time) : [];

        return (is_numeric($time[0] ?? null) ? (int) $time[0] : 0) + (is_numeric($time[1] ?? null) ? (int) $time[1] : 0) / 1e6;
    }
}
