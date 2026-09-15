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
use Artigo\Cache\MemoLock;
use Artigo\Cache\Tests\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The two halves of this package are deliberately separate classes - MemoLock
 * works with any Symfony pool, not only this one - so nothing guarantees they
 * compose except a test that puts them together.
 *
 * What has to survive the combination is the callback contract: the lock
 * decides who computes, and the pool still has to store the tags that
 * computation declared, or an invalidation afterwards would miss the item.
 */
final class VersionedWithMemoLockTest extends TestCase
{
    private \Redis|\Relay\Relay $redis;

    protected function setUp(): void
    {
        // MemoLock lives in artigo/cache-stampede now: the two are independent
        // and this is the one place that checks they still compose
        if (!class_exists(MemoLock::class)) {
            self::markTestSkipped('artigo/cache-stampede is not installed.');
        }

        $this->redis = Connection::openSingleNode();
        $this->clean();
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->clean();
        }
    }

    public function testTagsSurviveTheLockAndStillInvalidate(): void
    {
        $pool = $this->pool();
        $calls = 0;

        $resolve = static function (ItemInterface $item) use (&$calls): string {
            ++$calls;
            $item->expiresAfter(60);
            $item->tag(['articles', 'front-page']);

            return 'computed '.$calls;
        };

        self::assertSame('computed 1', $pool->get('article-1', $resolve));
        self::assertSame('computed 1', $pool->get('article-1', $resolve), 'the second read is a hit');
        self::assertSame(1, $calls);

        // the tags were declared inside a callback the lock wrapped: if they
        // were lost on the way through, this invalidation matches nothing
        $pool->invalidateTags(['articles']);

        self::assertSame('computed 2', $pool->get('article-1', $resolve));
        self::assertSame(2, $calls, 'the invalidated item was recomputed exactly once');
    }

    public function testAnUntaggedItemIsLeftAloneByTheInvalidation(): void
    {
        $pool = $this->pool();

        $pool->get('tagged', static function (ItemInterface $item): string {
            $item->expiresAfter(60);
            $item->tag(['articles']);

            return 'tagged value';
        });

        $pool->get('untagged', static function (ItemInterface $item): string {
            $item->expiresAfter(60);

            return 'untagged value';
        });

        $pool->invalidateTags(['articles']);

        $recomputed = false;
        $value = $pool->get('untagged', static function (ItemInterface $item) use (&$recomputed): string {
            $recomputed = true;

            return 'recomputed';
        });

        self::assertSame('untagged value', $value);
        self::assertFalse($recomputed, 'a tag it never carried must not touch it');
    }

    public function testTheLockIsReleasedSoTheNextReadIsNotBlocked(): void
    {
        $pool = $this->pool();

        $pool->get('released', static function (ItemInterface $item): string {
            $item->expiresAfter(60);

            return 'value';
        });

        self::assertSame([], $this->redis->keys('memolock:lock:*'));
    }

    private function pool(): VersionedRedisTagAwareAdapter
    {
        $pool = new VersionedRedisTagAwareAdapter($this->redis, 'combined-test');
        $pool->setCallbackWrapper(new MemoLock($this->redis, lockTtlMs: 5_000, waitTimeoutMs: 200, pollIntervalMs: 10));

        return $pool;
    }

    private function clean(): void
    {
        Connection::sweep($this->redis, 'combined-test*');
        Connection::sweep($this->redis, 'memolock:*');
    }
}
