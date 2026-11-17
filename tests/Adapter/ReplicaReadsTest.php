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
use PHPUnit\Framework\TestCase;

/**
 * Reads answered by a replica that lags (failover=slaves). A lagging replica
 * that lacks the id the set holds answers a refresh exactly as a lost stream
 * would, and a lost stream is a reason to drop the set: so on a connection
 * that may read from replicas, that answer is asked again of the primary, and
 * only the primary's answer may drop anything. A single node has no replica,
 * and its answer stands.
 *
 * The connections are stand-ins holding the stream in memory (FakeServer), so
 * the lag is exact rather than hoped for.
 */
final class ReplicaReadsTest extends TestCase
{
    public function testAnEmptyDeltaFromAReplicaDoesNotResetTheSet(): void
    {
        $cluster = new FakeCluster(\RedisCluster::FAILOVER_DISTRIBUTE_SLAVES);
        $held = $cluster->addRule('a', opened: true);
        $cluster->storeItem('replica:item', 'v', ['b'], $held);
        $pool = new VersionedRedisTagAwareAdapter($cluster, 'replica', rulesCacheMs: 0, rulesCache: false);

        self::assertTrue($pool->getItem('item')->isHit(), 'stamped with the rule the set holds');

        // the next refresh reaches a replica that has not caught up with it
        $cluster->replicaHolds = 0;

        self::assertTrue($pool->getItem('item')->isHit(), 'the primary still holds the rule, so nothing was lost');
        self::assertSame(1, $cluster->primaryReads, 'asked of the primary once');
    }

    public function testAnEmptyDeltaOnAStandaloneConnectionStillResets(): void
    {
        $redis = new FakeRedis();
        $held = $redis->addRule('a', opened: true);
        $redis->storeItem('replica:item', 'v', ['b'], $held);
        $pool = new VersionedRedisTagAwareAdapter($redis, 'replica', rulesCacheMs: 0, rulesCache: false);

        self::assertTrue($pool->getItem('item')->isHit());

        // the stream lost the entry the set holds
        $redis->replicaHolds = 0;

        self::assertFalse($pool->getItem('item')->isHit(), 'what the stream lost may have invalidated the item');
        self::assertSame(0, $redis->primaryReads, 'a single node is not asked twice');
    }

    public function testAfterAResetTheStreamIsReadFromTheStartOnThePrimary(): void
    {
        $cluster = new FakeCluster(\RedisCluster::FAILOVER_DISTRIBUTE_SLAVES);
        $first = $cluster->addRule('a', opened: true);
        $cluster->storeItem('replica:item', 'v', ['b'], $first);
        $cluster->addRule('b');
        $newest = $cluster->addRule('c');
        $pool = new VersionedRedisTagAwareAdapter($cluster, 'replica', rulesCacheMs: 0, rulesCache: false);

        // the set takes in every rule through another key, so the outdated
        // item is not read (and dropped) yet
        self::assertFalse($pool->getItem('witness')->isHit());

        // a failover loses the newest rule, another server appends one, and
        // the replica the next reads reach holds only the first rule
        unset($cluster->stream[$newest]);
        $cluster->addRule('after');
        $cluster->replicaHolds = 1;
        $cluster->primaryReads = 0;

        self::assertFalse($pool->getItem('item')->isHit(), 'the rule that outdated the item came from the primary, not from the replica that lacks it');
        self::assertSame(2, $cluster->primaryReads, 'the answer that reset the set, and the read from the start');
    }

    public function testAWriteAfterAReplicaReadReachesThePrimary(): void
    {
        $cluster = new FakeCluster(\RedisCluster::FAILOVER_DISTRIBUTE_SLAVES);
        $pool = new VersionedRedisTagAwareAdapter($cluster, 'replica', rulesCache: false);

        // the miss is read from a replica; the save that follows must not be
        // sent there
        $item = $pool->getItem('item');
        self::assertFalse($item->isHit());

        self::assertTrue($pool->save($item->set('v')));
        self::assertTrue($pool->getItem('item')->isHit());
    }

    public function testARefreshThatEndsBeforeAnOwnRuleIsAskedOfThePrimary(): void
    {
        $cluster = new FakeCluster(\RedisCluster::FAILOVER_DISTRIBUTE_SLAVES);
        $held = $cluster->addRule('a', opened: true);
        $cluster->storeItem('replica:item', 'v', ['b'], $held);
        $pool = new VersionedRedisTagAwareAdapter($cluster, 'replica', rulesCacheMs: 0, rulesCache: false);

        self::assertTrue($pool->getItem('item')->isHit());

        // another server appends a rule, then this pool invalidates the item's
        // tag: the set no longer ends where the pool's rule went, so the rule
        // is fetched rather than added from the reply
        $cluster->addRule('other');
        self::assertTrue($pool->invalidateTags(['b']));

        // and the replica the fetch reaches has neither rule yet
        $cluster->replicaHolds = 1;

        self::assertFalse($pool->getItem('item')->isHit(), 'the primary was asked, and holds the pool\'s own rule');
        self::assertSame(1, $cluster->primaryReads);
    }
}
