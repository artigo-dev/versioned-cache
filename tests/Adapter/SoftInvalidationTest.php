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
use Artigo\Cache\Stale\RefreshQueue;
use Artigo\Cache\StaleCache;
use Artigo\Cache\Tests\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * What a soft tag invalidation leaves: a miss for every read, and the value
 * kept for the grace, for whoever may serve it stale, until the next read,
 * write or delete of the key, or a clear or a hard invalidation, through the
 * pool. What stays hard: a hard rule, a delete, a clear.
 * hasItem() and prune() leave what a read may keep.
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

    public function testAnOwnDeleteOrClearDropsWhatAReadKept(): void
    {
        $this->write('deleted', 'before', 'menu');
        $this->write('cleared', 'before', 'menu');
        self::assertTrue($this->pool()->invalidateTags(['menu']));

        $reader = $this->pool();
        self::assertFalse($reader->getItem('deleted')->isHit());
        self::assertFalse($reader->getItem('cleared')->isHit());

        self::assertTrue($reader->deleteItem('deleted'));
        self::assertNull($reader->takeAgedItem('deleted'), 'a deleted value is not handed out');

        self::assertTrue($reader->clear());
        self::assertNull($reader->takeAgedItem('cleared'), 'nor a cleared one');
    }

    public function testAnOwnHardInvalidationDropsWhatAReadKept(): void
    {
        $writer = $this->pool();
        self::assertTrue($writer->save($writer->getItem('k')->set('before')->tag(['menu', 'price'])));
        self::assertTrue($this->pool()->invalidateTags(['menu']));

        $reader = $this->pool();
        $bystander = $this->pool();
        self::assertFalse($reader->getItem('k')->isHit());
        self::assertFalse($bystander->getItem('k')->isHit());

        // a hard rule on a tag the kept value carries
        self::assertTrue($reader->invalidateTags(['price'], hard: true));

        self::assertNull($reader->takeAgedItem('k'), 'invalidated hard after the read: not handed out');
        self::assertNotNull($bystander->takeAgedItem('k'), 'a pool that did not invalidate keeps what it read until its next read of the key');
    }

    public function testAnOwnWriteDropsWhatAReadKeptOfTheKeysItWrites(): void
    {
        $this->write('written', 'before', 'menu');
        $this->write('other', 'before', 'menu');
        self::assertTrue($this->pool()->invalidateTags(['menu']));

        $reader = $this->pool();
        self::assertFalse($reader->getItem('other')->isHit());
        $item = $reader->getItem('written');
        self::assertFalse($item->isHit());

        self::assertTrue($reader->save($item->set('after')->tag('menu')));

        self::assertNull($reader->takeAgedItem('written'), 'a value written over is not handed out');
        self::assertNotNull($reader->takeAgedItem('other'), 'the copy of a key it did not write stays');
        self::assertSame('after', $this->pool()->getItem('written')->get());
    }

    public function testAResetDropsWhatAReadKept(): void
    {
        $this->write('k', 'before', 'menu');
        self::assertTrue($this->pool()->invalidateTags(['menu']));

        $reader = $this->pool();
        self::assertFalse($reader->getItem('k')->isHit());

        // the end of a request, or of a message a worker handled
        $reader->reset();

        self::assertNull($reader->takeAgedItem('k'), 'a kept copy is the request\'s own');
    }

    public function testAReadAfterAHardChangeElsewhereDropsWhatAnEarlierReadKept(): void
    {
        $changes = [
            'a delete' => static fn (VersionedRedisTagAwareAdapter $pool, string $key): bool => $pool->deleteItem($key),
            'a hard rule' => static fn (VersionedRedisTagAwareAdapter $pool, string $key): bool => $pool->invalidateTags([$key], hard: true),
            'a clear' => static fn (VersionedRedisTagAwareAdapter $pool, string $key): bool => $pool->clear($key),
        ];

        foreach ($changes as $change => $apply) {
            $key = 'k'.md5($change);
            $pool = $this->pool();
            self::assertTrue($pool->save($pool->getItem($key)->set('before')->tag(['menu', $key])));
            self::assertTrue($this->pool()->invalidateTags(['menu']));

            // a read keeps it, and nothing takes it
            $reader = $this->pool();
            self::assertFalse($reader->getItem($key)->isHit());

            // elsewhere, the value changes for good; the next read finds that
            self::assertTrue($apply($this->pool(), $key));
            self::assertFalse($reader->getItem($key)->isHit());

            self::assertNull($reader->takeAgedItem($key), \sprintf('after %s, nothing is handed out', $change));
        }
    }

    public function testEveryReadOfTheKeyDecidesAfresh(): void
    {
        $this->write('k', 'before', 'menu');
        self::assertTrue($this->pool()->invalidateTags(['menu']));

        $reader = $this->pool();
        $reader->getItem('k');
        self::assertFalse($reader->hasItem('k'));
        self::assertNull($reader->takeAgedItem('k'), 'hasItem() is a read of the key too');

        $reader->getItem('k');
        self::assertNotNull($reader->takeAgedItem('k'), 'and the read after it keeps it again');
    }

    public function testAStaleCacheDoesNotServeADeletedValue(): void
    {
        $compute = static fn (string $value): \Closure => static function (ItemInterface $item) use ($value): string {
            $item->expiresAfter(300)->tag('menu');

            return $value;
        };

        $writer = new StaleCache($this->pool(), staleSeconds: 60, soft: true);
        $writer->get('k', $compute('before'));
        self::assertTrue($writer->invalidateTags(['menu']));

        // one request: a look at the key, a delete, a read
        $pool = new StaleCache($this->pool(), staleSeconds: 60, soft: true);
        $pool->setDeferrer(new RefreshQueue());

        self::assertFalse($pool->hasItem('k'));
        self::assertTrue($pool->deleteItem('k'));

        self::assertSame('computed after the delete', $pool->get('k', $compute('computed after the delete')), 'the deleted value is not served stale');
    }

    public function testPruneLeavesWhatOnlySoftRulesReachedWithinTheGrace(): void
    {
        $this->write('soft', 'before', 'menu');
        $this->write('hard', 'before', 'price');
        self::assertTrue($this->pool()->invalidateTags(['menu']));
        self::assertTrue($this->pool()->invalidateTags(['price'], hard: true));

        self::assertTrue($this->pool()->prune());

        self::assertSame(1, $this->exists('soft'), 'a soft rule within the grace: kept');
        self::assertSame(0, $this->exists('hard'), 'a hard rule: unlinked');

        $reader = $this->pool();
        self::assertFalse($reader->getItem('soft')->isHit());
        self::assertNotNull($reader->takeAgedItem('soft'), 'for the read that keeps it aged');

        self::assertTrue($this->pool(graceSeconds: 0)->prune());
        self::assertSame(0, $this->exists('soft'), 'a pool without a grace unlinks it');
    }

    public function testHasItemMissesWhatOnlySoftRulesReachedAndLeavesIt(): void
    {
        $this->write('soft', 'before', 'menu');
        $this->write('hard', 'before', 'price');
        self::assertTrue($this->pool()->invalidateTags(['menu']));
        self::assertTrue($this->pool()->invalidateTags(['price'], hard: true));

        self::assertFalse($this->pool()->hasItem('soft'), 'a miss, as every strict read');
        self::assertFalse($this->pool()->hasItem('hard'));

        self::assertSame(1, $this->exists('soft'), 'a soft rule within the grace: kept');
        self::assertSame(0, $this->exists('hard'), 'a hard rule: dropped');

        $reader = $this->pool();
        self::assertFalse($reader->getItem('soft')->isHit());
        self::assertNotNull($reader->takeAgedItem('soft'), 'for the read that keeps it aged');
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
