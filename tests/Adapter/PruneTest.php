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
use Symfony\Component\Cache\PruneableInterface;

/**
 * Invalidating deletes nothing here: a stale item is unlinked by the reader
 * that finds it, and one nobody reads again waits for its TTL. prune() is the
 * way out of that last case, and it must give back exactly what a reader
 * would have - no more, and nothing it could not reach a verdict on.
 */
final class PruneTest extends TestCase
{
    private \Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis;

    protected function setUp(): void
    {
        $this->redis = Connection::open();
        Connection::sweep($this->redis, 'prune*');
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            Connection::sweep($this->redis, 'prune*');
        }
    }

    public function testTheAdapterIsPruneable(): void
    {
        self::assertInstanceOf(PruneableInterface::class, $this->pool());
    }

    public function testPruneUnlinksWhatARuleInvalidatedAndLeavesTheRest(): void
    {
        $pool = $this->pool();
        $this->save($pool, 'gone', ['red']);
        $this->save($pool, 'kept', ['blue']);

        $pool->invalidateTags(['red']);

        // nothing has read them: both are still on the server
        self::assertSame(1, $this->redis->exists('prune:gone'));
        self::assertSame(1, $this->redis->exists('prune:kept'));

        self::assertTrue($pool->prune());

        self::assertSame(0, $this->redis->exists('prune:gone'), 'the invalidated item is gone');
        self::assertSame(1, $this->redis->exists('prune:kept'), 'and the one no rule names is not');
        self::assertTrue($pool->getItem('kept')->isHit());
    }

    public function testPruneKeepsTheRulesThemselves(): void
    {
        $pool = $this->pool();
        $this->save($pool, 'k', ['t']);
        $pool->invalidateTags(['t']);

        self::assertTrue($pool->prune());

        self::assertSame(1, $this->redis->exists('prune:@rules'), 'the record of the invalidations is not an item');
        self::assertFalse($pool->getItem('k')->isHit());
    }

    public function testPruneKeepsAnItemWrittenAfterTheRule(): void
    {
        $pool = $this->pool();
        $this->save($pool, 'k', ['t']);

        $pool->invalidateTags(['t']);
        // written after the rule: the same tag, a newer watermark
        $this->save($pool, 'k', ['t']);

        self::assertTrue($pool->prune());

        self::assertSame(1, $this->redis->exists('prune:k'));
        self::assertTrue($pool->getItem('k')->isHit(), 'a rule older than the item it names invalidates nothing');
    }

    public function testPruneGivesBackAWholePoolNobodyReadsAgain(): void
    {
        $pool = $this->pool();

        for ($i = 0; $i < 50; ++$i) {
            $this->save($pool, 'item-'.$i, ['everything', 'bucket-'.($i % 5)]);
        }

        $pool->invalidateTags(['everything']);

        self::assertSame(50, $this->keysMatching('prune:item-*'), 'four commands invalidated them and deleted none');

        self::assertTrue($pool->prune());

        self::assertSame(0, $this->keysMatching('prune:item-*'), 'and the prune took all fifty back without a reader');
    }

    public function testPruneStaysInsideItsOwnNamespace(): void
    {
        $mine = new VersionedRedisTagAwareAdapter($this->redis, 'prune', rulesCacheMs: 0, rulesCache: false);
        $theirs = new VersionedRedisTagAwareAdapter($this->redis, 'pruneother', rulesCacheMs: 0, rulesCache: false);

        $this->save($mine, 'k', ['t']);
        $this->save($theirs, 'k', ['t']);

        // a clear is one prefix rule, and deletes nothing either
        $mine->clear();

        self::assertTrue($mine->prune());

        self::assertSame(0, $this->redis->exists('prune:k'));
        self::assertSame(1, $this->redis->exists('pruneother:k'), 'another pool sharing the server is not walked');
        self::assertTrue($theirs->getItem('k')->isHit());
    }

    public function testPruneWithoutTheRulesDeletesNothingAndSaysSo(): void
    {
        $redis = Connection::openSingleNodeAs(BrokenRulesPruneRedis::class);
        Connection::sweep($redis, 'prune*');

        $writer = new VersionedRedisTagAwareAdapter($redis, 'prune', rulesCacheMs: 0, rulesCache: false);
        $this->save($writer, 'k', ['t']);
        $writer->invalidateTags(['t']);

        // a process that has never held the rules, and now cannot read them
        $redis->broken = true;
        $pool = new VersionedRedisTagAwareAdapter($redis, 'prune', rulesCacheMs: 0, rulesCache: false);

        self::assertFalse($pool->prune(), 'a verdict it could not reach is not a verdict');
        self::assertSame(1, $redis->exists('prune:k'), 'and nothing was deleted on the strength of it');

        $redis->broken = false;

        self::assertTrue($pool->prune());
        self::assertSame(0, $redis->exists('prune:k'));
    }

    private function pool(): VersionedRedisTagAwareAdapter
    {
        // exact reads, so a rule appended a moment ago is in the snapshot the
        // prune takes; the window is what rulesCacheMs is for, not this
        return new VersionedRedisTagAwareAdapter($this->redis, 'prune', rulesCacheMs: 0, rulesCache: false);
    }

    /**
     * @param list<string> $tags
     */
    private function save(VersionedRedisTagAwareAdapter $pool, string $key, array $tags): void
    {
        $item = $pool->getItem($key);
        $item->set('v');
        $item->tag($tags);
        $pool->save($item);
    }

    private function keysMatching(string $pattern): int
    {
        $found = 0;

        if ($this->redis instanceof \RedisCluster || $this->redis instanceof \Relay\Cluster) {
            foreach ($this->redis->_masters() as $master) {
                if (!\is_array($master) && !\is_string($master)) {
                    continue;
                }

                $cursor = null;

                do {
                    $keys = $this->redis->scan($cursor, $master, $pattern, 500);
                    $found += \is_array($keys) ? \count($keys) : 0;
                } while ($cursor);
            }

            return $found;
        }

        $cursor = null;

        do {
            $keys = $this->redis->scan($cursor, $pattern, 500);
            $found += \is_array($keys) ? \count($keys) : 0;
        } while ($cursor);

        return $found;
    }
}

/**
 * A connection whose rules stream cannot be read, so that a prune has nothing
 * to judge by.
 */
final class BrokenRulesPruneRedis extends \Redis
{
    public bool $broken = false;

    /**
     * @return \Redis|array<mixed>|bool
     */
    public function xRange(string $key, string $start, string $end, int $count = -1): \Redis|array|bool
    {
        if ($this->broken) {
            throw new \RedisException('the node holding the rules stream is down');
        }

        return parent::xRange($key, $start, $end, $count);
    }
}
