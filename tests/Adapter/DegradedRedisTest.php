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
use Artigo\Cache\Exception\InvalidArgumentException;
use Artigo\Cache\Tests\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\RedisAdapter;

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
        $writer = new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0);

        $item = $writer->getItem('k');
        $item->set('v');
        $item->tag(['t']);
        $writer->save($item);
        // something is in the stream, so "no rules" would be a real answer
        $writer->invalidateTags(['other']);

        // a process that has never loaded the rules, and now cannot
        $redis->broken = true;
        $pool = new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0);

        self::assertFalse($pool->getItem('k')->isHit(), 'without the rules, fresh cannot be told from stale: a miss, never a hit');
        self::assertFalse($pool->hasItem('k'));
        self::assertSame(1, $redis->exists('degraded:k'), 'a verdict that could not be reached unlinks nothing');

        $redis->broken = false;

        self::assertTrue($pool->getItem('k')->isHit(), 'and the item is back the moment the rules are');
    }

    public function testAProcessHoldingRulesKeepsServingWhileTheyCannotBeRefreshed(): void
    {
        $redis = $this->open(BrokenRulesRedis::class);
        $pool = new VersionedRedisTagAwareAdapter($redis, 'degraded', rulesCacheMs: 0);

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
        $pool = new VersionedRedisTagAwareAdapter($redis, 'degraded');

        $item = $pool->getItem('deferred');
        $item->set('v');
        $pool->saveDeferred($item);
        $pool->reset();

        self::assertTrue((new VersionedRedisTagAwareAdapter($redis, 'degraded'))->getItem('deferred')->isHit(), 'reset() commits what was deferred, as every Symfony adapter does');
    }

    public function testAPipelineThatDiesAnswersMissesRatherThanThrowing(): void
    {
        $redis = $this->open(DeadPipelineRedis::class);
        $pool = new VersionedRedisTagAwareAdapter($redis, 'degraded');

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
            new VersionedRedisTagAwareAdapter($redis, 'degraded');
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
            new VersionedRedisTagAwareAdapter($redis, 'degraded');
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

/**
 * A connection whose pipeline never comes back, as phpredis reports when the
 * socket dies between the buffered writes and the read.
 */
final class DeadPipelineRedis extends \Redis
{
    public bool $dead = false;

    /**
     * @return \Redis|array<mixed>|false
     */
    public function exec(): \Redis|array|false
    {
        if ($this->dead) {
            parent::discard();

            return false;
        }

        return parent::exec();
    }
}
