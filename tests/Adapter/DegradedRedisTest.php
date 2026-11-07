<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests\Adapter;

use Artigo\Cache\Adapter\SharedRules;
use Artigo\Cache\Adapter\VersionedRedisTagAwareAdapter;
use Artigo\Cache\Exception\InvalidArgumentException;
use Artigo\Cache\Tests\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * A cache must never be the reason a request fails, and it must never serve
 * an invalidated item because something else broke. Redis is broken here on
 * purpose, one piece at a time, and both promises are checked.
 */
final class DegradedRedisTest extends TestCase
{
    /** @var list<\Redis> */
    private array $connections = [];

    protected function tearDown(): void
    {
        foreach ($this->connections as $redis) {
            Connection::sweep($redis, 'degraded*');
        }
    }

    public function testRulesThatCannotBeLoadedMakeEveryReadAMissAndUnlinkNothing(): void
    {
        $redis = $this->open(BrokenRulesRedis::class);
        $writer = new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0, rulesCache: false);

        $item = $writer->getItem('k');
        $item->set('v');
        $item->tag(['t']);
        $writer->save($item);
        // something is in the stream, so "no rules" would be a real answer
        $writer->invalidateTags(['other']);

        // a process that has never loaded the rules, and now cannot
        $redis->broken = true;
        $pool = new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0, rulesCache: false);

        self::assertFalse($pool->getItem('k')->isHit(), 'without the rules, fresh cannot be told from stale: a miss, never a hit');
        self::assertFalse($pool->hasItem('k'));
        self::assertSame(1, $redis->exists('degraded:k'), 'a verdict that could not be reached unlinks nothing');

        // the outage is this pool's own: one that can read the rules finds the item
        $redis->broken = false;

        self::assertTrue((new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0, rulesCache: false))->getItem('k')->isHit(), 'and the item is back once the rules are');
    }

    public function testAValueWhoseReadCouldNotLoadTheRulesIsReturnedNotStored(): void
    {
        $redis = $this->open(BrokenRulesRedis::class);
        $writer = new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0, rulesCache: false);
        self::assertTrue($writer->save($writer->getItem('k')->set('stored')->tag('t')));
        self::assertTrue($writer->invalidateTags(['other']));
        $before = $redis->hGetAll('degraded:k');

        // reads that never reached a verdict: misses, whatever the item holds
        $redis->broken = true;
        $pool = new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0, rulesCache: false);

        // a get() computing after its miss: the item may well be valid
        self::assertSame('computed', $pool->get('k', static fn (): string => 'computed'), 'the caller gets its value');
        self::assertSame($before, $redis->hGetAll('degraded:k'), 'and the item is left as it was');

        // a key that holds nothing is left holding nothing
        self::assertSame('computed', $pool->get('absent', static fn (): string => 'computed'));
        self::assertSame(0, $redis->exists('degraded:absent'));

        $redis->broken = false;

        self::assertSame('stored', (new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0, rulesCache: false))->getItem('k')->get(), 'a valid item stays valid');
    }

    public function testAWriteMeantToReplaceAValueWhoseReadCouldNotLoadTheRulesDropsIt(): void
    {
        $writes = [
            'a forced recomputation' => static fn (VersionedRedisTagAwareAdapter $pool, string $key): bool => $key === $pool->get($key, static fn (ItemInterface $item): string => $item->getKey(), \INF),
            'a save after a read' => static function (VersionedRedisTagAwareAdapter $pool, string $key): bool {
                $item = $pool->getItem($key);

                return false === $item->isHit() && false === $pool->save($item->set('new'));
            },
        ];

        // every connection first: opening one sweeps what the others wrote
        $redis = $this->open(BrokenRulesRedis::class);
        $reads = $this->open(\Redis::class);
        $writer = new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0, rulesCache: false);
        self::assertTrue($writer->invalidateTags(['other']));

        foreach ($writes as $write => $apply) {
            $key = 'k'.md5($write);
            self::assertTrue($writer->save($writer->getItem($key)->set('old')));

            // the rules cannot be read, and the caller replaces the value
            $redis->broken = true;
            self::assertTrue($apply(new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0, rulesCache: false), $key), \sprintf('%s: the caller gets its value, and it is not stored', $write));
            $redis->broken = false;

            self::assertSame(['e'], array_keys((array) $reads->hGetAll('degraded:'.$key)), \sprintf('after %s, a tombstone where the value was', $write));

            $reader = new VersionedRedisTagAwareAdapter($reads, 'degraded', rulesCacheMs: 0, rulesCache: false);
            self::assertFalse($reader->getItem($key)->isHit(), \sprintf('after %s, the value it was to replace is not served', $write));
        }
    }

    public function testAWriteWhoseReadMissedTheRulesIsNeverServedAfterALostStream(): void
    {
        // every connection first: opening one sweeps what the others wrote
        $redis = $this->open(BrokenRulesRedis::class);
        $other = new VersionedRedisTagAwareAdapter($this->open(\Redis::class), 'degraded', rulesCacheMs: 0, rulesCache: false);
        $reads = $this->open(\Redis::class);
        self::assertTrue($other->invalidateTags(['unrelated']));

        // the read cannot load the rules; a rule on its tag falls while it is computed
        $redis->broken = true;
        $pool = new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0, rulesCache: false);
        $pool->get('k', static function (ItemInterface $item) use ($redis, $other): string {
            $redis->broken = false;
            $item->tag('t');
            self::assertTrue($other->invalidateTags(['t']));

            return 'computed before the rule';
        });

        // the stream is lost with that rule, and a new one opens
        $reads->del('degraded:@rules');
        self::assertTrue($other->invalidateTags(['later']));

        $reader = new VersionedRedisTagAwareAdapter($reads, 'degraded', rulesCacheMs: 0, rulesCache: false);
        self::assertFalse($reader->getItem('k')->isHit(), 'it was never stored');
    }

    public function testAPlainWriteWithNoRuleSetDropsTheValueItReplaces(): void
    {
        $failures = [
            'a fetch that throws' => static function (BrokenRulesRedis $redis): void {
                $redis->broken = true;
            },
            'a fetch answered with an error reply' => static function (BrokenRulesRedis $redis): void {
                $redis->answersFalse = true;
            },
        ];

        $redis = $this->open(BrokenRulesRedis::class);
        $other = new VersionedRedisTagAwareAdapter($this->open(\Redis::class), 'degraded', rulesCacheMs: 0, rulesCache: false);
        self::assertTrue($other->invalidateTags(['unrelated']));

        foreach ($failures as $failure => $break) {
            foreach ([60 => ['e'], 0 => []] as $window => $left) {
                $key = 'k'.md5($failure).$window;
                self::assertTrue($other->save($other->getItem($key)->set('stored')));

                // a write this pool never read, while it holds no rule set and cannot load one
                $item = $other->getItem($key);
                $break($redis);
                $pool = new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0, rulesCache: false, guardWindowSeconds: $window);

                self::assertFalse($pool->save($item->set('written')), \sprintf('%s: refused', $failure));
                self::assertSame($left, array_keys((array) $redis->hGetAll('degraded:'.$key)), \sprintf('%s, guard window %d: the value it replaces is dropped', $failure, $window));

                $redis->broken = false;
                $redis->answersFalse = false;
            }
        }
    }

    public function testAWriteWhoseItemReadFailedDropsTheValueItWouldReplace(): void
    {
        $writes = [
            'a forced recomputation' => static fn (VersionedRedisTagAwareAdapter $pool, DeadPipelineRedis $redis, string $key): mixed => $pool->get($key, static function () use ($redis): string {
                $redis->dead = false;

                return 'new';
            }, \INF),
            'a save after a read' => static function (VersionedRedisTagAwareAdapter $pool, DeadPipelineRedis $redis, string $key): bool {
                $item = $pool->getItem($key);
                $redis->dead = false;

                return $pool->save($item->set('new'));
            },
        ];

        // every connection first: opening one sweeps what the others wrote
        $redis = $this->open(DeadPipelineRedis::class);
        $reads = $this->open(\Redis::class);

        foreach ($writes as $write => $apply) {
            $key = 'k'.md5($write);
            $writer = new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0, rulesCache: false);
            self::assertTrue($writer->save($writer->getItem($key)->set('old')));

            // the read of the item never comes back, so its epoch is unknown
            $redis->dead = true;
            $apply(new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0, rulesCache: false), $redis, $key);

            $reader = new VersionedRedisTagAwareAdapter($reads, 'degraded', rulesCacheMs: 0, rulesCache: false);
            self::assertFalse($reader->getItem($key)->isHit(), \sprintf('after %s, the value it was to replace is not served', $write));

            // the next computation reads an epoch, and lands
            self::assertSame('newer', $reader->get($key, static fn (): string => 'newer'));
            self::assertSame('newer', (new VersionedRedisTagAwareAdapter($reads, 'degraded', rulesCacheMs: 0, rulesCache: false))->getItem($key)->get());
        }
    }

    public function testAnItemReadThatThrowsGuardsTheWriteAfterIt(): void
    {
        // every connection first: opening one sweeps what the others wrote
        $redis = $this->open(DeadPipelineRedis::class);
        $other = new VersionedRedisTagAwareAdapter($this->open(\Redis::class), 'degraded', rulesCacheMs: 0, rulesCache: false);
        $reads = $this->open(\Redis::class);
        self::assertTrue($other->save($other->getItem('k')->set('old')->tag('t')));
        self::assertTrue($other->invalidateTags(['unrelated']));

        // the item read times out, which phpredis raises from the pipeline
        $redis->throws = true;
        $pool = new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0, rulesCache: false);
        $value = $pool->get('k', static function (ItemInterface $item) use ($redis, $other): string {
            $redis->throws = false;
            $item->tag('t');
            self::assertTrue($other->invalidateTags(['t']));

            return 'computed before the rule';
        }, \INF);

        self::assertSame('computed before the rule', $value);
        self::assertSame(['e'], array_keys((array) $reads->hGetAll('degraded:k')), 'a tombstone where the old value was');

        $reader = new VersionedRedisTagAwareAdapter($reads, 'degraded', rulesCacheMs: 0, rulesCache: false);
        self::assertFalse($reader->getItem('k')->isHit(), 'a value computed before the rule is not served past it');
    }

    public function testTheOwnComputationAfterAFailedItemReadLeavesTheKey(): void
    {
        $redis = $this->open(DeadPipelineRedis::class);
        $writer = new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0, rulesCache: false);
        self::assertTrue($writer->save($writer->getItem('k')->set('stored')));
        $before = $redis->hGetAll('degraded:k');

        // the read of the item never comes back, so its epoch is unknown
        $pool = new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0, rulesCache: false);
        $redis->dead = true;
        $value = $pool->get('k', static function () use ($redis): string {
            $redis->dead = false;

            return 'computed';
        });

        self::assertSame('computed', $value, 'the caller gets its value');
        self::assertSame($before, $redis->hGetAll('degraded:k'), 'and the key is left as it was');
    }

    public function testAWriteRefusedForAnUnknownEpochIsRefusedAgainUntilTheNextRead(): void
    {
        $redis = $this->open(DeadPipelineRedis::class);
        $reads = $this->open(\Redis::class);
        $writer = new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0, rulesCache: false);
        self::assertTrue($writer->save($writer->getItem('k')->set('old')));

        $pool = new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0, rulesCache: false);
        $redis->dead = true;
        $item = $pool->getItem('k');
        $redis->dead = false;

        self::assertFalse($pool->save($item->set('new')));
        self::assertFalse($pool->save($item->set('new')), 'a retry without a read is refused too');
        self::assertSame(['e'], array_keys((array) $reads->hGetAll('degraded:k')), 'and the tombstone stays');
    }

    public function testAWriteRefusedForAnUnknownEpochUnlinksAValueOfAnotherType(): void
    {
        $redis = $this->open(\Redis::class);
        self::assertTrue($redis->set('degraded:k', 'not a hash'));

        // the read of a value of another type fails, so its epoch is unknown
        $pool = new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0, rulesCache: false);
        $item = $pool->getItem('k');
        self::assertFalse($item->isHit());

        self::assertFalse($pool->save($item->set('new')));
        self::assertSame(0, $redis->exists('degraded:k'), 'a tombstone cannot be put on it, so it goes');
    }

    public function testADoubtfulReadPastTheWindowRefusesTheWriteAfterItOnce(): void
    {
        // every connection first: opening one sweeps what the others wrote
        $broken = $this->open(BrokenRulesRedis::class);
        $dead = $this->open(DeadPipelineRedis::class);
        $writer = new VersionedRedisTagAwareAdapter($broken, 'degraded', rulesCacheMs: 0, rulesCache: false, guardWindowSeconds: 1);
        self::assertTrue($writer->invalidateTags(['other']));
        self::assertTrue($writer->save($writer->getItem('unknown')->set('old')));

        // a read that reached no verdict, and one that learned no epoch
        $unjudged = new VersionedRedisTagAwareAdapter($broken, 'degraded', rulesCacheMs: 0, rulesCache: false, guardWindowSeconds: 1);
        $broken->broken = true;
        $noVerdict = $unjudged->getItem('unjudged');
        $broken->broken = false;

        $unknown = new VersionedRedisTagAwareAdapter($dead, 'degraded', rulesCacheMs: 0, rulesCache: false, guardWindowSeconds: 1);
        $dead->dead = true;
        $noEpoch = $unknown->getItem('unknown');
        $dead->dead = false;

        // saves that come long after them, past the window
        usleep(1_100_000);

        self::assertFalse($unjudged->save($noVerdict->set('later')), 'past the window a read still refuses the write after it');
        self::assertFalse($unknown->save($noEpoch->set('later')));
        self::assertSame(['e'], array_keys((array) $broken->hGetAll('degraded:unjudged')), 'a save after it deletes');
        self::assertSame(['e'], array_keys((array) $broken->hGetAll('degraded:unknown')));

        // once: then the read is forgotten, and the write after that lands
        self::assertTrue($unjudged->save($noVerdict->set('later')));
        self::assertTrue($unknown->save($noEpoch->set('later')));
        self::assertSame('later', $unjudged->getItem('unjudged')->get());
        self::assertSame('later', $unknown->getItem('unknown')->get());
    }

    public function testAComputationThatOutlastsTheWindowAfterAReadWithoutRulesIsNotStored(): void
    {
        $redis = $this->open(BrokenRulesRedis::class);
        $writer = new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0, rulesCache: false, guardWindowSeconds: 1);
        self::assertTrue($writer->invalidateTags(['other']));
        self::assertTrue($writer->save($writer->getItem('k')->set('stored')));
        $before = $redis->hGetAll('degraded:k');

        // the read cannot load the rules; the computation outlasts the window, and by its end they are back
        $redis->broken = true;
        $pool = new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0, rulesCache: false, guardWindowSeconds: 1);
        $pool->get('k', static function () use ($redis): string {
            usleep(1_100_000);
            $redis->broken = false;

            return 'computed blind';
        });

        self::assertSame($before, $redis->hGetAll('degraded:k'), 'not stored: the key is left as it was');
    }

    public function testAResetForgetsTheDoubtfulReads(): void
    {
        $redis = $this->open(\Redis::class);
        $writer = new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0, rulesCache: false);
        self::assertTrue($writer->invalidateTags(['other']));

        // no rule set held while another worker reloads: a read that reaches no verdict
        $medium = new ArrayAdapter();
        $reloading = SharedRules::of($medium, 'degraded:@rules', $redis);
        self::assertNotNull($reloading);
        self::assertTrue($reloading->lead());
        $pool = new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0, rulesCache: $medium);
        $item = $pool->getItem('k');
        $reloading->release();

        // the request ends; the next one saves without reading first
        $pool->reset();

        self::assertTrue($pool->save($item->set('next request')));
        self::assertSame('next request', $pool->getItem('k')->get());
    }

    public function testAChangeWhileAComputationWhoseReadMissedTheRulesRunsIsNeverServed(): void
    {
        $changes = [
            'a delete' => static fn (VersionedRedisTagAwareAdapter $pool, string $key): bool => $pool->deleteItem($key),
            'a tag invalidation' => static fn (VersionedRedisTagAwareAdapter $pool, string $key): bool => $pool->invalidateTags([$key]),
        ];

        // every connection first: opening one sweeps what the others wrote
        $redis = $this->open(BrokenRulesRedis::class);
        $other = new VersionedRedisTagAwareAdapter($this->open(\Redis::class), 'degraded', rulesCacheMs: 0, rulesCache: false);
        $reads = $this->open(\Redis::class);

        // the stream holds rules, as it does in production
        self::assertTrue($other->invalidateTags(['unrelated']));

        foreach ($changes as $change => $apply) {
            $key = 'k'.md5($change);
            $pool = new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0, rulesCache: false);

            // the read cannot load the rules; by the write they are back
            $redis->broken = true;
            $value = $pool->get($key, static function (ItemInterface $item) use ($redis, $other, $apply, $key): string {
                $redis->broken = false;
                $item->tag($key);
                self::assertTrue($apply($other, $key));

                return 'computed from what was there before';
            });

            self::assertSame('computed from what was there before', $value, 'the caller still gets its value');

            $reader = new VersionedRedisTagAwareAdapter($reads, 'degraded', rulesCacheMs: 0, rulesCache: false);
            self::assertFalse($reader->getItem($key)->isHit(), \sprintf('%s during the computation stands', $change));
        }
    }

    public function testAReadWithoutTheRulesArmsNoOtherKey(): void
    {
        $redis = $this->open(BrokenRulesRedis::class);
        $pool = new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0, rulesCache: false);
        $redis->broken = true;
        $inner = null;

        $pool->get('outer', static function () use ($redis, $pool, &$inner): string {
            // the rules are back, and the callback reads another key
            $redis->broken = false;
            $inner = $pool->getItem('inner');

            return 'outer';
        });

        // a delete elsewhere, after that read
        $elsewhere = new VersionedRedisTagAwareAdapter($this->open(BrokenRulesRedis::class), 'degraded', rulesCacheMs: 0, rulesCache: false);
        self::assertTrue($elsewhere->deleteItem('inner'));

        self::assertInstanceOf(CacheItem::class, $inner);
        self::assertFalse($pool->save($inner->set('computed before the delete')), 'the outer get() ended without taking the inner read along');
    }

    public function testAProcessHoldingRulesKeepsServingWhileTheyCannotBeRefreshed(): void
    {
        $redis = $this->open(BrokenRulesRedis::class);
        $pool = new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0, rulesCache: false);

        $item = $pool->getItem('k');
        $item->set('v');
        $item->tag(['t']);
        $pool->save($item);
        $pool->invalidateTags(['other']);

        self::assertTrue($pool->getItem('k')->isHit(), 'the rules are held now');

        $redis->broken = true;

        self::assertTrue($pool->getItem('k')->isHit(), 'the last known set still honours every invalidation seen so far');
    }

    public function testDeferredItemsSurviveAReset(): void
    {
        $redis = $this->open(\Redis::class);
        $pool = new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCache: false);

        $item = $pool->getItem('deferred');
        $item->set('v');
        $pool->saveDeferred($item);
        $pool->reset();

        self::assertTrue((new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCache: false))->getItem('deferred')->isHit(), 'reset() commits what was deferred, as every Symfony adapter does');
    }

    public function testAPipelineThatDiesAnswersMissesRatherThanThrowing(): void
    {
        $redis = $this->open(DeadPipelineRedis::class);
        $pool = new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCache: false);

        $item = $pool->getItem('a');
        $item->set('v');
        $pool->save($item);

        $redis->dead = true;

        $hits = 0;

        foreach ($pool->getItems(['a', 'b']) as $fetched) {
            $hits += $fetched->isHit() ? 1 : 0;
        }

        self::assertSame(0, $hits, 'a batch that never came back is a batch of misses');

        $item = $pool->getItem('c');
        $item->set('v');

        self::assertFalse($pool->save($item), 'and a write that never landed says so');
    }

    public function testAClientThatSerializesOnItsOwnIsRefused(): void
    {
        $redis = $this->open(\Redis::class);
        $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_PHP);

        try {
            $this->expectException(InvalidArgumentException::class);
            new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCache: false);
        } finally {
            $redis->setOption(\Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE);
        }
    }

    public function testAClientThatCompressesOnItsOwnIsRefused(): void
    {
        if (!\defined('Redis::OPT_COMPRESSION') || !\defined('Redis::COMPRESSION_LZF') || !\defined('Redis::COMPRESSION_ZSTD')) {
            self::markTestSkipped('this phpredis was built without compression');
        }

        $redis = $this->open(\Redis::class);

        foreach ([\Redis::COMPRESSION_ZSTD, \Redis::COMPRESSION_LZF] as $compression) {
            if ($redis->setOption(\Redis::OPT_COMPRESSION, $compression)) {
                break;
            }
        }

        if (\Redis::COMPRESSION_NONE === $redis->getOption(\Redis::OPT_COMPRESSION)) {
            self::markTestSkipped('this phpredis accepts no compression algorithm');
        }

        try {
            $this->expectException(InvalidArgumentException::class);
            new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCache: false);
        } finally {
            $redis->setOption(\Redis::OPT_COMPRESSION, \Redis::COMPRESSION_NONE);
        }
    }

    public function testSymfonysOwnAdapterAgreesOnTheResetContract(): void
    {
        // the reference the test above holds this adapter to
        $redis = $this->open(\Redis::class);
        $pool = new RedisAdapter($redis, 'degraded-symfony');

        $item = $pool->getItem('deferred');
        $item->set('v');
        $pool->saveDeferred($item);
        $pool->reset();

        self::assertTrue((new RedisAdapter($redis, 'degraded-symfony'))->getItem('deferred')->isHit());
    }

    /**
     * @template T of \Redis
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function open(string $class): \Redis
    {
        $redis = Connection::openSingleNodeAs($class);
        $this->connections[] = $redis;
        Connection::sweep($redis, 'degraded*');

        return $redis;
    }
}

/**
 * A connection whose rules stream cannot be read: what a cluster node holding
 * the stream going away looks like to the adapter.
 */
final class BrokenRulesRedis extends \Redis
{
    public bool $broken = false;

    /** The stream answers false, as phpredis does on an error reply (BUSY, LOADING) */
    public bool $answersFalse = false;

    /**
     * @return \Redis|array<mixed>|bool
     */
    public function xRange(string $key, string $start, string $end, int $count = -1): \Redis|array|bool
    {
        if ($this->broken) {
            throw new \RedisException('the node holding the rules stream is down');
        }

        if ($this->answersFalse) {
            return false;
        }

        return parent::xRange($key, $start, $end, $count);
    }
}

/**
 * A connection whose pipeline never comes back, as phpredis reports when the
 * socket dies between the buffered writes and the read.
 */
final class DeadPipelineRedis extends \Redis
{
    public bool $dead = false;

    /** The pipeline raises instead, as phpredis does on a read timeout */
    public bool $throws = false;

    /**
     * @return \Redis|array<mixed>|false
     */
    public function exec(): \Redis|array|false
    {
        if ($this->throws) {
            parent::discard();

            throw new \RedisException('read error on connection');
        }

        if ($this->dead) {
            parent::discard();

            return false;
        }

        return parent::exec();
    }
}
