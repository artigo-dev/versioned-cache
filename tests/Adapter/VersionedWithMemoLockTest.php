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
use Artigo\Cache\StaleCache;
use Artigo\Cache\Tests\Connection;
use Composer\InstalledVersions;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\CacheItem;
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

    public function testARefusedWriteReleasesTheLockAndTheNextReaderComputes(): void
    {
        $pool = $this->pool();
        $elsewhere = new VersionedRedisTagAwareAdapter(Connection::openSingleNode(), 'combined-test', rulesCache: false);

        $value = $pool->get('raced', static function (ItemInterface $item) use ($elsewhere): string {
            $item->expiresAfter(60);
            $elsewhere->deleteItem('raced');

            return 'computed across a delete';
        }, metadata: $metadata);

        self::assertSame('computed across a delete', $value, 'the caller still gets its value');
        self::assertSame([], $this->redis->keys('memolock:lock:*'), 'the refused write released the lock');

        $began = microtime(true);
        $next = $this->pool()->get('raced', static fn (): string => 'computed by the next reader');

        self::assertSame('computed by the next reader', $next, 'the refused value was never stored');
        self::assertLessThan(1.0, microtime(true) - $began, 'and nobody waited for it');

        if (self::symfonyReportsAFailedSave() && self::stampedeReportsTheSave($this->redis)) {
            self::assertIsArray($metadata);
            self::assertTrue($metadata[ItemInterface::METADATA_SAVE_FAILED] ?? false, 'the refusal reached the caller');
        }
    }

    public function testTheWaiterSecondReadLetsItsWriteLand(): void
    {
        // a delete elsewhere left a tombstone that lives one second
        $elsewhere = new VersionedRedisTagAwareAdapter(Connection::openSingleNode(), 'combined-test', rulesCache: false, guardWindowSeconds: 1);
        $elsewhere->deleteItem('waited');

        // and a holder keeps the lock past that second
        $this->redis->set('memolock:lock:'.self::tagFor('waited'), 'a-holders-token', ['PX' => 1_300]);

        // the waiter's first read saw the tombstone; the read it makes after
        // waiting sees it gone, and its write must compare with that one.
        // Timed on the server's clock, which expires the lock and the tombstone
        $began = $this->serverTime();
        $value = $this->pool()->get('waited', static function (ItemInterface $item): string {
            $item->expiresAfter(60);

            return 'computed after the wait';
        });

        self::assertGreaterThan(1.0, $this->serverTime() - $began, 'it waited past the tombstone');
        self::assertSame('computed after the wait', $value);
        self::assertSame('computed after the wait', $this->pool()->getItem('waited')->get(), 'it landed');
    }

    public function testADeleteDuringAStaleRefreshRefusesItsWrite(): void
    {
        $stale = new StaleCache($this->pool(), staleSeconds: 60, election: new MemoLock($this->redis));

        // a value whose lifetime ended a second ago
        $item = $stale->getItem('refreshed')->set('old');
        $item->expiresAt(\DateTimeImmutable::createFromFormat('U.u', \sprintf('%.6F', microtime(true) - 1)) ?: null);
        self::assertTrue($stale->save($item));

        $elsewhere = new VersionedRedisTagAwareAdapter(Connection::openSingleNode(), 'combined-test', rulesCache: false);

        // served stale and refreshed inline; a delete lands while it computes
        $value = $stale->get('refreshed', static function (ItemInterface $item) use ($elsewhere): string {
            $item->expiresAfter(60);
            $elsewhere->deleteItem('refreshed');

            return 'computed across a delete';
        });

        self::assertSame('computed across a delete', $value, 'the caller still gets what it computed');
        self::assertFalse($this->pool()->getItem('refreshed')->isHit(), 'the refresh was guarded like a miss: the delete stands');
    }

    public function testAForcedRefreshAcrossADeleteUnderALockLands(): void
    {
        if (!self::stampedeReadsAgainAfterAForcedWait($this->redis)) {
            self::markTestSkipped('This artigo/cache-stampede forces a recomputation without reading the item again after the wait.');
        }

        $pool = $this->pool();
        self::assertTrue($pool->save($pool->getItem('forced')->set('before')));

        // a delete elsewhere left a tombstone that lives one second
        $elsewhere = new VersionedRedisTagAwareAdapter(Connection::openSingleNode(), 'combined-test', rulesCache: false, guardWindowSeconds: 1);
        $elsewhere->deleteItem('forced');

        // and a holder keeps the lock past that second
        $this->redis->set('memolock:lock:'.self::tagFor('forced'), 'a-holders-token', ['PX' => 1_300]);

        // INF: computed after the wait whatever is there, and compared with
        // the read the lock made after it, not with the one before it
        $began = $this->serverTime();
        $value = $this->pool()->get('forced', static function (ItemInterface $item): string {
            $item->expiresAfter(60);

            return 'forced after the wait';
        }, \INF);

        self::assertGreaterThan(1.0, $this->serverTime() - $began, 'it waited past the tombstone');
        self::assertSame('forced after the wait', $value);
        self::assertSame('forced after the wait', $this->pool()->getItem('forced')->get(), 'it landed');
    }

    /**
     * Whether symfony/cache puts a failed save in get()'s $metadata, which it
     * does from 8.2 on. The constant alone says nothing: it comes with
     * symfony/cache-contracts, which earlier versions install as well.
     */
    private static function symfonyReportsAFailedSave(): bool
    {
        $version = InstalledVersions::getVersion('symfony/cache');

        return null !== $version && version_compare($version, '8.2.0.0-dev', '>=');
    }

    /**
     * Whether the installed cache-stampede reports what save() answered
     * through $setMetadata, which its 2.0 does.
     */
    private static function stampedeReportsTheSave(\Redis|\Relay\Relay $redis): bool
    {
        $calls = 0;
        $pool = new ArrayAdapter();
        $save = true;
        $lock = new MemoLock($redis);

        $lock->__invoke(static fn (): string => 'v', $pool->getItem('probe'), $save, $pool, static function () use (&$calls): void {
            ++$calls;
        });

        return 2 === $calls;
    }

    /**
     * Whether the installed cache-stampede reads the item again when a forced
     * recomputation has waited for the lock, which its 2.0 does.
     */
    private static function stampedeReadsAgainAfterAForcedWait(\Redis|\Relay\Relay $redis): bool
    {
        $pool = new class extends ArrayAdapter {
            private int $reads = 0;

            public function getItem(mixed $key): CacheItem
            {
                ++$this->reads;

                return parent::getItem($key);
            }

            public function reads(): int
            {
                return $this->reads;
            }
        };
        $item = $pool->getItem('probe-forced');
        $before = $pool->reads();
        $save = true;

        // a holder that lets go within a few polls
        $redis->set('memolock:lock:'.self::tagFor('probe-forced'), 'a-holders-token', ['PX' => 60]);
        $lock = new MemoLock($redis);
        $lock->__invoke(static fn (): string => 'v', $item, $save, $pool, null, null, \INF);

        return $before < $pool->reads();
    }

    private static function tagFor(string $key): string
    {
        return '{'.strtr(base64_encode(hash('xxh128', $key, true)), '+/', '-_').'}';
    }

    private function pool(): VersionedRedisTagAwareAdapter
    {
        $pool = new VersionedRedisTagAwareAdapter($this->redis, 'combined-test', rulesCache: false);
        $pool->setCallbackWrapper(new MemoLock($this->redis, lockTtlMs: 5_000, waitTimeoutMs: 200, pollIntervalMs: 10));

        return $pool;
    }

    /**
     * The server's clock, in seconds: what expires a lock and a tombstone,
     * where a container's clock may run apart from ours.
     */
    private function serverTime(): float
    {
        $time = $this->redis->time();
        $time = \is_array($time) ? array_values($time) : [];

        return (is_numeric($time[0] ?? null) ? (int) $time[0] : 0) + (is_numeric($time[1] ?? null) ? (int) $time[1] : 0) / 1e6;
    }

    private function clean(): void
    {
        Connection::sweep($this->redis, 'combined-test*');
        Connection::sweep($this->redis, 'memolock:*');
    }
}
