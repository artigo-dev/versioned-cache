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
 * How the adapter talks to the rules stream: what a read costs once the rules
 * are held, what a write costs once they are fresh, and which watermark an
 * item computed after a miss is stamped with.
 *
 * Two pools stand for two processes: what one appends, the other has to
 * notice - and notice cheaply.
 */
final class RulesStreamTest extends TestCase
{
    private SpyRedis $redis;
    private \Redis $other;

    protected function setUp(): void
    {
        $this->redis = Connection::openSingleNodeAs(SpyRedis::class);
        $this->other = Connection::openSingleNodeAs(\Redis::class);
        Connection::sweep($this->other, 'stream*');
    }

    protected function tearDown(): void
    {
        if (isset($this->other)) {
            Connection::sweep($this->other, 'stream*');
        }
    }

    public function testARuleAppendedElsewhereIsFetchedFromTheLastIdHeld(): void
    {
        $pool = new VersionedRedisTagAwareAdapter($this->redis, 'stream', rulesCacheMs: 0);
        $elsewhere = new VersionedRedisTagAwareAdapter($this->other, 'stream');

        $item = $pool->getItem('k');
        $item->set('v');
        $item->tag(['t']);
        $pool->save($item);

        // something to hold: a rule that does not touch the item
        $elsewhere->invalidateTags(['other']);
        self::assertTrue($pool->getItem('k')->isHit());

        $held = array_key_first((array) $this->other->xRange('stream:@rules', '-', '+'));
        $this->redis->starts = [];

        $elsewhere->invalidateTags(['t']);

        self::assertFalse($pool->getItem('k')->isHit(), 'the other process\'s rule reached this one');
        self::assertSame([$held], $this->redis->starts, 'the range started at the id already held, not at the beginning');
    }

    public function testAWriteAfterAFreshReadCostsNoWatermarkRoundTrip(): void
    {
        $pool = new VersionedRedisTagAwareAdapter($this->redis, 'stream', rulesCacheMs: 1_000);

        $item = $pool->getItem('k');
        $this->redis->watermarks = 0;

        $item->set('v');
        $pool->save($item);

        self::assertSame(0, $this->redis->watermarks, 'the rule set read for the miss already knows the stream\'s head');

        $exact = new VersionedRedisTagAwareAdapter($this->redis, 'stream', rulesCacheMs: 0);
        $item = $exact->getItem('k2');
        $this->redis->watermarks = 0;

        $item->set('v');
        $exact->save($item);

        self::assertSame(1, $this->redis->watermarks, 'with no cache window there is nothing fresh to read it from');
    }

    public function testAnItemCommittedAfterAnInvalidationIsFresh(): void
    {
        // Symfony's TagAwareTestTrait::testInvalidateCommits pins this: the
        // watermark is the stream's head at write time, whatever happened
        // between computing the value and writing it
        $pool = new VersionedRedisTagAwareAdapter($this->redis, 'stream', rulesCacheMs: 0);
        $elsewhere = new VersionedRedisTagAwareAdapter($this->other, 'stream');

        $item = $pool->getItem('slow');
        self::assertFalse($item->isHit());

        $elsewhere->invalidateTags(['t']);

        $item->set('computed before the change, written after it');
        $item->tag(['t']);
        $pool->save($item);

        self::assertTrue($pool->getItem('slow')->isHit());
    }

    public function testAStreamDeletedUnderARunningProcessIsForgotten(): void
    {
        $pool = new VersionedRedisTagAwareAdapter($this->redis, 'stream', rulesCacheMs: 0);
        $elsewhere = new VersionedRedisTagAwareAdapter($this->other, 'stream');

        $item = $pool->getItem('x');
        $item->set('v');
        $item->tag(['t']);
        $pool->save($item);

        $elsewhere->invalidateTags(['t']);
        self::assertFalse($pool->getItem('x')->isHit(), 'this process now holds the rule');

        // an operator flushes the cache: items and stream are gone
        $this->other->del('stream:@rules');

        $item = $pool->getItem('y');
        $item->set('v');
        $item->tag(['t']);
        $pool->save($item);

        self::assertTrue($pool->getItem('y')->isHit(), 'a rule the server no longer holds must not keep invalidating here');
    }

    public function testAnInvalidatedItemDoesNotComeBackWhenTheStreamIsLost(): void
    {
        $pool = new VersionedRedisTagAwareAdapter($this->redis, 'stream', rulesCacheMs: 0);
        $elsewhere = new VersionedRedisTagAwareAdapter($this->other, 'stream');

        // a rule before the item, so the item carries a real watermark
        $elsewhere->invalidateTags(['earlier']);

        $item = $pool->getItem('x');
        $item->set('v');
        $item->tag(['t']);
        $pool->save($item);

        $elsewhere->invalidateTags(['t']);

        // evicted, deleted, or gone with its slot - before anyone read the item
        $this->other->del('stream:@rules');

        self::assertFalse($pool->getItem('x')->isHit(), 'the stream forgot the rule; the item must not be served past it');
    }

    public function testAnInvalidatedItemDoesNotComeBackWhenTheStreamIsRebuiltByAnotherRule(): void
    {
        $pool = new VersionedRedisTagAwareAdapter($this->redis, 'stream', rulesCacheMs: 0);
        $elsewhere = new VersionedRedisTagAwareAdapter($this->other, 'stream');

        $elsewhere->invalidateTags(['earlier']);

        $item = $pool->getItem('x');
        $item->set('v');
        $item->tag(['t']);
        $pool->save($item);

        $elsewhere->invalidateTags(['t']);
        $this->other->del('stream:@rules');

        // a reborn stream takes its ids from the server clock, so a host fast
        // enough to run all of this inside one millisecond opens it on the id
        // the item already carries - and an id that is not newer than the
        // stamp says nothing was lost. Let the clock move first.
        usleep(2_000);

        // the next invalidation, of anything at all, opens a new stream: the
        // head is fresh again, and the rule for "t" is nowhere in it
        $elsewhere->invalidateTags(['unrelated']);

        self::assertFalse($pool->getItem('x')->isHit(), 'the stream opened after the item was written: what it lost may have invalidated the item');
    }

    public function testAnItemWrittenAfterTheLossIsServedThroughTheNextRule(): void
    {
        $pool = new VersionedRedisTagAwareAdapter($this->redis, 'stream', rulesCacheMs: 0);
        $elsewhere = new VersionedRedisTagAwareAdapter($this->other, 'stream');

        $elsewhere->invalidateTags(['earlier']);
        $this->other->del('stream:@rules');

        // written against the empty stream: it has seen no rule, so nothing
        // the stream lost can concern it
        $item = $pool->getItem('y');
        $item->set('v');
        $item->tag(['t']);
        $pool->save($item);

        $elsewhere->invalidateTags(['unrelated']);

        self::assertTrue($pool->getItem('y')->isHit(), 'a rule for another tag does not touch it, and the reborn stream is not held against it');
    }

    public function testTheFirstRuleOfAPoolLeavesUnmatchedItemsAlone(): void
    {
        // no stream exists yet: the first rule opens it, and an opening rule
        // must not read as a rebuild
        $pool = new VersionedRedisTagAwareAdapter($this->redis, 'stream', rulesCacheMs: 0);

        foreach (['a', 'b'] as $tag) {
            $item = $pool->getItem($tag);
            $item->set('v');
            $item->tag([$tag]);
            $pool->save($item);
        }

        $pool->invalidateTags(['a']);

        self::assertFalse($pool->getItem('a')->isHit());
        self::assertTrue($pool->getItem('b')->isHit(), 'the opening rule judges by tags alone');
    }

    public function testTheRulesStreamCarriesNoTtl(): void
    {
        $pool = new VersionedRedisTagAwareAdapter($this->redis, 'stream', rulesCacheMs: 0);

        $pool->invalidateTags(['t']);
        self::assertSame(-1, $this->other->pttl('stream:@rules'), 'a key with a TTL is an eviction candidate under volatile-*');

        // a stream left behind by an earlier version, which gave it one
        $this->other->pexpire('stream:@rules', 60_000);
        $pool->invalidateTags(['t']);

        self::assertSame(-1, $this->other->pttl('stream:@rules'), 'the next rule strips it');
    }

    public function testASubNamespaceClearLeavesTheRestOfThePoolAlone(): void
    {
        $pool = new VersionedRedisTagAwareAdapter($this->redis, 'stream', rulesCacheMs: 0);

        foreach (['foo.1', 'foo.2', 'bar.1'] as $key) {
            $item = $pool->getItem($key);
            $item->set('v');
            $pool->save($item);
        }

        self::assertTrue($pool->clear('foo.'));

        self::assertFalse($pool->getItem('foo.1')->isHit());
        self::assertFalse($pool->getItem('foo.2')->isHit());
        self::assertTrue($pool->getItem('bar.1')->isHit());
    }
}

/**
 * A connection that remembers where each XRANGE started and how often the
 * watermark was asked for.
 */
final class SpyRedis extends \Redis
{
    /** @var list<string> */
    public array $starts = [];

    public int $watermarks = 0;

    /**
     * @return \Redis|array<mixed>|bool
     */
    public function xRange(string $key, string $start, string $end, int $count = -1): \Redis|array|bool
    {
        $this->starts[] = $start;

        return parent::xRange($key, $start, $end, $count);
    }

    /**
     * @return \Redis|array<mixed>|bool
     */
    public function xRevRange(string $key, string $end, string $start, int $count = -1): \Redis|array|bool
    {
        ++$this->watermarks;

        return parent::xRevRange($key, $end, $start, $count);
    }
}
