<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests\Adapter;

use Artigo\Cache\Adapter\InvalidationRules;
use Artigo\Cache\Adapter\SharedRules;
use Artigo\Cache\Adapter\VersionedRedisTagAwareAdapter;
use Artigo\Cache\Tests\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The rule set shared by the workers of a server: what a fresh pool pays
 * when another one has already loaded the rules, how a rule appended by this
 * process or by another one reaches everybody, and who refreshes when several
 * workers cross the window together.
 *
 * Two pools over two connections, sharing one ArrayAdapter, stand for two
 * workers on one server - the same shape APCu gives them in production,
 * without needing APCu here. The election flag is a file lock, so it is
 * real even inside one process: a second handle cannot take a held lock.
 */
final class SharedRulesTest extends TestCase
{
    private const NAMESPACE = 'shared';
    private const RULES_KEY = 'shared:@rules';

    private SpyRedis $redis;
    private SpyRedis $other;
    private \Redis $plain;
    private ArrayAdapter $shared;

    protected function setUp(): void
    {
        $this->redis = Connection::openSingleNodeAs(SpyRedis::class);
        $this->other = Connection::openSingleNodeAs(SpyRedis::class);
        $this->plain = Connection::openSingleNodeAs(\Redis::class);
        $this->shared = new ArrayAdapter();
        Connection::sweep($this->plain, self::NAMESPACE.'*');
    }

    protected function tearDown(): void
    {
        if (isset($this->plain)) {
            Connection::sweep($this->plain, self::NAMESPACE.'*');
        }
    }

    public function testASecondPoolAdoptsTheSharedSetWithoutAFetch(): void
    {
        $first = $this->pool($this->redis);
        $this->write($first, 'item1', ['tag1']);
        $this->write($first, 'item2', ['tag2']);
        $first->invalidateTags(['tag1']);
        // loads the stream, and leaves the set behind for the others
        self::assertTrue($first->getItem('item2')->isHit());

        $second = $this->pool($this->other);

        self::assertFalse($second->getItem('item1')->isHit(), 'the adopted set carries the rule');
        self::assertSame([], $this->other->starts, 'nothing was fetched from Redis for it');
    }

    public function testAFreshSharedSetDoesNotHideThisPoolsOwnInvalidation(): void
    {
        $first = $this->pool($this->redis);
        $this->write($first, 'item1', ['tag1']);
        $this->write($first, 'item2', ['tag2']);
        $this->write($first, 'item3', ['tag1']);
        // the shared set is stored here, fresh, without the rule below
        self::assertTrue($first->getItem('item2')->isHit());
        $fetches = \count($this->redis->starts);

        $first->invalidateTags(['tag1']);

        self::assertFalse($first->getItem('item1')->isHit(), 'a process sees what it just invalidated');
        self::assertCount($fetches + 1, $this->redis->starts, 'through one delta fetch');

        // and shares it back: a second pool adopts what the first fetched
        $second = $this->pool($this->other);

        self::assertFalse($second->getItem('item3')->isHit());
        self::assertSame([], $this->other->starts);
    }

    public function testARuleAppendedElsewhereIsSeenAfterTheWindowThroughOneDelta(): void
    {
        $first = $this->pool($this->redis, 100);
        $this->write($first, 'item1', ['tag1']);
        $this->write($first, 'item2', ['tag2']);
        $first->invalidateTags(['tag2']);
        self::assertTrue($first->getItem('item1')->isHit());
        $last = $this->streamHead();

        // another server invalidates tag1: straight into the stream
        $this->appendRule('tag1');

        $second = $this->pool($this->other, 100);

        self::assertTrue($second->getItem('item1')->isHit(), 'within the window the adopted set does not know the rule yet');
        usleep(150_000);
        self::assertFalse($second->getItem('item1')->isHit(), 'after it, the rule has arrived');
        self::assertSame([$last], $this->other->starts, 'through one delta fetch from the id held');
    }

    public function testASlowerRefreshNeverRollsTheEntryBack(): void
    {
        $shared = $this->sharedRules();

        $older = new InvalidationRules(self::NAMESPACE.':', 60_000);
        $older->absorb(['1000-0' => ['t' => '{"t":["a"]}', 'first' => '1']]);
        $newer = new InvalidationRules(self::NAMESPACE.':', 60_000);
        $newer->absorb(['1000-0' => ['t' => '{"t":["a"]}', 'first' => '1'], '2000-0' => ['t' => '{"t":["b"]}', 'first' => '0']]);

        self::assertTrue($shared->store($newer, 1.0));
        self::assertFalse($shared->store($older, 2.0), 'the older set is refused, however fresh its stamp');

        $loaded = $shared->load(self::NAMESPACE.':', 60_000);

        self::assertNotNull($loaded);
        self::assertSame('2000-0', $loaded[0]->last());
    }

    public function testAStaleSharedSetIsRefreshedByTheElectedWorkerAlone(): void
    {
        $first = $this->pool($this->redis, 50);
        $this->write($first, 'item1', ['tag1']);
        $this->write($first, 'item2', ['tag2']);
        $first->invalidateTags(['tag2']);
        self::assertTrue($first->getItem('item1')->isHit());
        $last = $this->streamHead();

        // another server invalidates tag1, and the window passes
        $this->appendRule('tag1');
        usleep(60_000);

        // a worker elsewhere on this server is refreshing right now
        $flag = $this->sharedRules();
        self::assertTrue($flag->lead());

        try {
            $second = $this->pool($this->other, 50);

            self::assertTrue($second->getItem('item1')->isHit(), 'keeps the stale set for this read');
            self::assertSame([], $this->other->starts, 'and fetches nothing');
        } finally {
            $flag->release();
        }

        self::assertFalse($second->getItem('item1')->isHit(), 'now it leads, and the rule is in');
        self::assertSame([$last], $this->other->starts, 'through one delta fetch');
        self::assertFalse($flag->isRefreshing(), 'and the flag was handed back');
    }

    public function testAColdWorkerWaitsForTheElectedLoaderThenLoadsOnItsOwn(): void
    {
        $first = $this->pool($this->redis);
        $this->write($first, 'item', ['tag1']);
        $first->invalidateTags(['tag2']);

        // nothing shared, and the flag says a loader is at work
        $flag = $this->sharedRules();
        $flag->forget();
        self::assertTrue($flag->lead());

        try {
            $second = $this->pool($this->other);
            $began = microtime(true);
            $hit = $second->getItem('item')->isHit();
            $waited = microtime(true) - $began;
        } finally {
            $flag->release();
        }

        self::assertTrue($hit, 'loaded on its own, correctly');
        self::assertSame(['-'], $this->other->starts, 'from the beginning, once');
        self::assertGreaterThan(0.2, $waited, 'after waiting the budget out');
    }

    public function testExactReadsElectNobody(): void
    {
        $first = $this->pool($this->redis, 0);
        $this->write($first, 'item', ['tag1']);
        $first->invalidateTags(['tag2']);
        self::assertTrue($first->getItem('item')->isHit());
        $fetches = \count($this->redis->starts);

        $flag = $this->sharedRules();
        self::assertTrue($flag->lead());

        try {
            self::assertTrue($first->getItem('item')->isHit());
        } finally {
            $flag->release();
        }

        self::assertCount($fetches + 1, $this->redis->starts, 'an exact read fetches whatever the flag says');
    }

    public function testFalseKeepsTheSetPerPool(): void
    {
        $first = $this->pool($this->redis, 1_000, false);
        $this->write($first, 'item', ['tag1']);
        $first->invalidateTags(['tag2']);
        self::assertTrue($first->getItem('item')->isHit());

        $second = $this->pool($this->other, 1_000, false);

        self::assertTrue($second->getItem('item')->isHit());
        self::assertSame(['-'], $this->other->starts, 'nothing to adopt: the stream is loaded from the beginning');
        self::assertFalse($this->shared->hasItem($this->sharedRules()->getKey()), 'and nothing was shared');
    }

    public function testTheEntryIsKeyedByTheConnectionToo(): void
    {
        self::assertNotSame(SharedRules::keyFor('k', 'host-a:6379:0'), SharedRules::keyFor('k', 'host-b:6379:0'));
        self::assertSame(SharedRules::keyFor('k', 'host-a:6379:0'), SharedRules::keyFor('k', 'host-a:6379:0'));
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_.]+$/', SharedRules::keyFor(self::RULES_KEY, SharedRules::identityOf($this->plain)), 'a PSR-6 key without reserved characters');
    }

    public function testTheDefaultMediumIsApcuWhereItIsEnabled(): void
    {
        self::assertNull(SharedRules::of(false, self::RULES_KEY, $this->plain));
        self::assertFalse($this->sharedRules()->isApcu(), 'a pool handed in is used as such');

        $default = SharedRules::of(null, self::RULES_KEY, $this->plain);

        if (!SharedRules::apcuEnabled()) {
            self::assertNull($default, 'nothing to share through without APCu');

            return;
        }

        self::assertNotNull($default);
        self::assertTrue($default->isApcu(), 'APCu, spoken to directly');

        // the round trip, as the pools use it
        $rules = new InvalidationRules(self::NAMESPACE.':', 60_000);
        $rules->absorb(['1000-0' => ['t' => '{"t":["a"]}', 'first' => '1']]);

        try {
            self::assertTrue($default->store($rules, 12.5));
            $loaded = $default->load(self::NAMESPACE.':', 60_000);

            self::assertNotNull($loaded);
            self::assertSame('1000-0', $loaded[0]->last());
            self::assertSame(12.5, $loaded[1]);
        } finally {
            $default->forget();
        }

        self::assertNull($default->load(self::NAMESPACE.':', 60_000), 'forgotten');
    }

    private function pool(SpyRedis $redis, int $rulesCacheMs = 1_000, CacheItemPoolInterface|false|null $rulesCache = null): VersionedRedisTagAwareAdapter
    {
        return new VersionedRedisTagAwareAdapter($redis, self::NAMESPACE, rulesCacheMs: $rulesCacheMs, rulesCache: $rulesCache ?? $this->shared);
    }

    /**
     * The shared set as the pools see it, for the test to take the flag or
     * read the entry.
     */
    private function sharedRules(): SharedRules
    {
        $shared = SharedRules::of($this->shared, self::RULES_KEY, $this->redis);

        self::assertNotNull($shared);

        return $shared;
    }

    /**
     * @param list<string> $tags
     */
    private function write(VersionedRedisTagAwareAdapter $pool, string $key, array $tags): void
    {
        $item = $pool->getItem($key);
        $item->set('v');
        $item->tag($tags);
        $pool->save($item);
    }

    /**
     * A rule as cache_versioned_invalidate would write it, from somewhere else.
     */
    private function appendRule(string $tag): void
    {
        $this->plain->xAdd(self::RULES_KEY, '*', ['t' => json_encode(['t' => [$tag]], \JSON_THROW_ON_ERROR), 'first' => '0']);
    }

    private function streamHead(): string
    {
        $head = array_key_first((array) $this->plain->xRevRange(self::RULES_KEY, '+', '-', 1));

        self::assertIsString($head);

        return $head;
    }
}
