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
use Symfony\Component\Cache\CacheItem;

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
    private SpyRedis $third;
    private \Redis $plain;
    private ArrayAdapter $shared;

    protected function setUp(): void
    {
        $this->redis = Connection::openSingleNodeAs(SpyRedis::class);
        $this->other = Connection::openSingleNodeAs(SpyRedis::class);
        $this->third = Connection::openSingleNodeAs(SpyRedis::class);
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
        self::assertCount($fetches, $this->redis->starts, 'from the reply, with nothing fetched');

        // and shares it back: a second pool adopts what the first fetched
        $second = $this->pool($this->other);

        self::assertFalse($second->getItem('item3')->isHit());
        self::assertSame([], $this->other->starts);
    }

    public function testTheNextRequestOnTheSameServerSeesTheInvalidation(): void
    {
        // a worker reads, and shares a fresh set with no rule for tag1
        $reader = $this->pool($this->redis);
        $this->write($reader, 'item', ['tag1']);
        self::assertTrue($reader->getItem('item')->isHit());

        // another request here only invalidates: it holds no set to add the
        // rule to, so the shared set is still the one without it
        self::assertTrue($this->pool($this->third)->invalidateTags(['tag1']));

        // the next request adopts that set inside its window
        $next = $this->pool($this->other);

        self::assertFalse($next->getItem('item')->isHit(), 'a set read before an invalidation made on this server is not taken for fresh');
    }

    public function testAnOwnRuleIsHeldFromTheReplyNotFromARead(): void
    {
        $pool = $this->pool($this->redis);
        $this->write($pool, 'item', ['tag1']);
        self::assertTrue($pool->getItem('item')->isHit());
        $starts = $this->redis->starts;

        $pool->invalidateTags(['tag1']);

        self::assertFalse($pool->getItem('item')->isHit(), 'the rule is held');
        self::assertSame($starts, $this->redis->starts, 'and nothing was read for it');

        $shared = $this->sharedRules()->load(self::NAMESPACE.':', 60_000);

        self::assertNotNull($shared);
        self::assertSame($this->streamHead(), $shared[0]->last(), 'the set ends at the id the server answered, and was shared');
    }

    public function testARuleAppendedInBetweenIsNotSkipped(): void
    {
        $pool = $this->pool($this->redis);
        $this->write($pool, 'item1', ['tag1']);
        $this->write($pool, 'item2', ['tag2']);
        self::assertTrue($pool->getItem('item2')->isHit());

        // another server invalidates tag2 after this pool read the stream, and
        // this pool's own rule follows it: the set held does not end where the
        // own rule was appended
        $this->appendRule('tag2');
        $pool->invalidateTags(['tag1']);

        self::assertFalse($pool->getItem('item1')->isHit());
        self::assertFalse($pool->getItem('item2')->isHit(), 'the rule in between was fetched, not skipped');
    }

    public function testBehindALocalInvalidationAWorkerFetchesEvenWhileAnotherRefreshes(): void
    {
        $reader = $this->pool($this->redis);
        $this->write($reader, 'item', ['tag1']);
        self::assertTrue($reader->getItem('item')->isHit());
        self::assertTrue($this->pool($this->third)->invalidateTags(['tag1']));

        // a worker elsewhere on this server is refreshing, and may have
        // started before the rule was appended
        $flag = $this->sharedRules();
        self::assertTrue($flag->lead());

        try {
            $next = $this->pool($this->other);

            self::assertFalse($next->getItem('item')->isHit(), 'the read fetches the rule itself rather than keep the set it adopted');
        } finally {
            $flag->release();
        }
    }

    public function testAColdWorkerDoesNotTakeASetFromBeforeALocalInvalidationAsFinal(): void
    {
        $this->write($this->pool($this->redis, rulesCache: false), 'item', ['tag1']);

        // the set the elected worker is about to share: read before the
        // invalidation below, so it lacks the rule
        $scratch = new ArrayAdapter();
        $early = SharedRules::of($scratch, self::RULES_KEY, $this->redis);
        self::assertNotNull($early);
        $rules = new InvalidationRules(self::NAMESPACE.':', 2_592_000_000);
        $rules->absorb((array) $this->plain->xRange(self::RULES_KEY, '-', '+'));
        self::assertTrue($early->store($rules, microtime(true) * 1000));
        usleep(2_000);

        // a medium that shows nothing at the first look, and that set from the
        // next one on: the leader's store landing while the cold worker waits
        $medium = new class extends ArrayAdapter {
            /** @var array<string, mixed> */
            public array $later = [];

            public function getItem(mixed $key): CacheItem
            {
                $item = parent::getItem($key);

                if (!$item->isHit() && \array_key_exists($key, $this->later)) {
                    $this->save(parent::getItem($key)->set($this->later[$key]));
                    unset($this->later[$key]);
                }

                return $item;
            }
        };
        $medium->later[$early->getKey()] = $scratch->getItem($early->getKey())->get();

        self::assertTrue($this->pool($this->third, rulesCache: $medium)->invalidateTags(['tag1']));

        // the elected worker is still at work
        $flag = SharedRules::of($medium, self::RULES_KEY, $this->redis);
        self::assertNotNull($flag);
        self::assertTrue($flag->lead());

        try {
            $cold = $this->pool($this->other, rulesCache: $medium);

            self::assertFalse($cold->getItem('item')->isHit(), 'the set it waited for is behind the invalidation: it loads the rules itself');
        } finally {
            $flag->release();
        }

        self::assertSame(['-'], $this->other->starts, 'once, from the beginning');
    }

    public function testASetReadAfterTheMarkIsFreshEvenWhereTheStreamLostTheRule(): void
    {
        self::assertTrue($this->pool($this->third)->invalidateTags(['tag1']));

        // the stream is flushed after the invalidation: no set read now can
        // hold the rule the mark names
        $this->plain->del(self::RULES_KEY);

        $pool = $this->pool($this->redis);
        $this->write($pool, 'item', ['tag2']);
        $starts = \count($this->redis->starts);

        self::assertTrue($pool->getItem('item')->isHit());
        self::assertTrue($pool->getItem('item')->isHit());
        self::assertCount($starts, $this->redis->starts, 'read after the mark: fresh for its window, not fetched on every read');
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

    public function testWithinTheGraceAHeldSetIsServedWhileAnotherWorkerRefreshes(): void
    {
        // past the window, within the grace: another server's rule not yet in
        [, $aged] = $this->shareASetAged(50 + intdiv($this->graceMs(), 2));
        $flag = $this->sharedRules();
        self::assertTrue($flag->lead());

        try {
            $second = $this->pool($this->other, 50);
            $began = microtime(true);

            self::assertTrue($second->getItem('item1')->isHit(), 'the set it holds is served as it is');
            self::assertLessThan(0.1, microtime(true) - $began, 'without waiting for the elected worker');
            self::assertSame([], $this->other->starts, 'and without a fetch');
        } finally {
            $flag->release();
        }

        $this->assertSharedEntryIs($aged);
    }

    public function testPastTheGraceAWorkerFetchesWhatItLacksWithoutWaiting(): void
    {
        [$last, $aged] = $this->shareASetAged(50 + $this->graceMs() + 500);
        $flag = $this->sharedRules();
        self::assertTrue($flag->lead());

        try {
            $second = $this->pool($this->other, 50);
            $began = microtime(true);

            self::assertFalse($second->getItem('item1')->isHit(), 'past the grace the rule from elsewhere is in');
            self::assertLessThan(0.1, microtime(true) - $began, 'without waiting for the elected worker');
            self::assertSame([$last], $this->other->starts, 'through one delta fetch from the id it held');
        } finally {
            $flag->release();
        }

        $this->assertSharedEntryIs($aged);
    }

    public function testPastTheGraceAStreamThatCannotBeReadLeavesTheHeldSetServed(): void
    {
        [$last, $aged] = $this->shareASetAged(50 + $this->graceMs() + 500);
        $flag = $this->sharedRules();
        self::assertTrue($flag->lead());
        $this->other->rangesFail = true;

        try {
            $second = $this->pool($this->other, 50);
            $began = microtime(true);

            self::assertTrue($second->getItem('item1')->isHit(), 'the set it holds, rather than no rules at all');
            self::assertTrue($second->getItem('item1')->isHit());
            self::assertLessThan(0.1, microtime(true) - $began, 'without waiting for the elected worker');
            self::assertSame([$last, $last], $this->other->starts, 'one failed delta fetch per read');
        } finally {
            $flag->release();
        }

        $this->assertSharedEntryIs($aged);
    }

    public function testAWorkerPastTheWindowAdoptsAFresherSharedSetAtOnce(): void
    {
        $first = $this->pool($this->redis, 50);
        $this->write($first, 'item', ['tag1']);
        $first->invalidateTags(['tag2']);

        // a worker that holds a set of its own
        $worker = $this->pool($this->other, 50);
        self::assertTrue($worker->getItem('item')->isHit());
        $this->other->forgetRanges();

        // the window passes, another server invalidates tag1, and the elected
        // worker has stored the stream as it is now
        usleep(60_000);
        $this->appendRule('tag1');
        $rules = new InvalidationRules(self::NAMESPACE.':', 2_592_000_000);
        $rules->absorb((array) $this->plain->xRange(self::RULES_KEY, '-', '+'));
        self::assertTrue($this->sharedRules()->store($rules, microtime(true) * 1000));

        $flag = $this->sharedRules();
        self::assertTrue($flag->lead());

        try {
            self::assertFalse($worker->getItem('item')->isHit(), 'the fresher set is adopted, rather than the held one served for the grace');
            self::assertSame([], $this->other->starts, 'and nothing was fetched for it');
        } finally {
            $flag->release();
        }
    }

    public function testAColdWorkerAdoptsTheElectedLoadWhileItWaits(): void
    {
        // a medium whose entry the elected worker stores while the other waits
        $medium = new class extends ArrayAdapter {
            /** @var array<string, mixed> */
            public array $later = [];

            /** @var array<string, int> */
            private array $looks = [];

            public function getItem(mixed $key): CacheItem
            {
                // the first look finds nothing, the next the elected worker's set
                if (\array_key_exists($key, $this->later)) {
                    $this->looks[$key] = ($this->looks[$key] ?? 0) + 1;

                    if (2 <= $this->looks[$key]) {
                        $this->save(parent::getItem($key)->set($this->later[$key]));
                        unset($this->later[$key]);
                    }
                }

                return parent::getItem($key);
            }
        };

        $this->write($this->pool($this->redis, rulesCache: false), 'item', ['tag1']);
        $this->appendRule('tag1');
        [$key, $entry] = $this->electedEntry();
        $medium->later[$key] = $entry;

        $flag = SharedRules::of($medium, self::RULES_KEY, $this->redis);
        self::assertNotNull($flag);
        self::assertTrue($flag->lead());

        try {
            $cold = $this->pool($this->other, rulesCache: $medium);
            $began = microtime(true);

            self::assertFalse($cold->getItem('item')->isHit(), 'the elected worker\'s set holds the rule');
            self::assertLessThan(0.2, microtime(true) - $began, 'taken while it waited, not after the budget');
            self::assertSame([], $this->other->starts, 'and nothing was fetched for it');
        } finally {
            $flag->release();
        }
    }

    public function testAColdWorkerWaitingTakesNoSetReadBeforeTheWindow(): void
    {
        // a medium whose entry shows up at the second look: while the worker waits
        $medium = new class extends ArrayAdapter {
            /** @var array<string, mixed> */
            public array $later = [];

            /** @var array<string, int> */
            private array $looks = [];

            public function getItem(mixed $key): CacheItem
            {
                if (\array_key_exists($key, $this->later)) {
                    $this->looks[$key] = ($this->looks[$key] ?? 0) + 1;

                    if (2 <= $this->looks[$key]) {
                        $this->save(parent::getItem($key)->set($this->later[$key]));
                        unset($this->later[$key]);
                    }
                }

                return parent::getItem($key);
            }
        };

        $this->write($this->pool($this->redis, rulesCache: false), 'item', ['tag1']);
        $this->appendRule('tag2');

        // a set read longer ago than the window, and a rule appended since
        [$key, $entry, $last] = $this->electedEntry(2_000);
        $medium->later[$key] = $entry;
        $this->appendRule('tag1');

        $flag = SharedRules::of($medium, self::RULES_KEY, $this->redis);
        self::assertNotNull($flag);
        self::assertTrue($flag->lead());

        try {
            $cold = $this->pool($this->other, rulesCache: $medium);

            self::assertFalse($cold->getItem('item')->isHit(), 'the rule appended after that set was read is in');
            self::assertSame([$last], $this->other->starts, 'fetched on its own, from where the set it took ends');
        } finally {
            $flag->release();
        }
    }

    public function testAColdWorkerAdoptsASetStoredJustBeforeTheElectedWorkerLetGo(): void
    {
        // a medium whose entry the elected worker stores, and whose flag it
        // lets go, right after a look of the waiting worker found nothing
        $medium = new class extends ArrayAdapter {
            /** @var array<string, mixed> */
            public array $later = [];

            public ?SharedRules $leader = null;

            /** @var array<string, int> */
            private array $looks = [];

            public function getItem(mixed $key): CacheItem
            {
                $item = parent::getItem($key);

                // the second look is the first while waiting: it finds
                // nothing, and the next look comes after the flag is free
                if (\array_key_exists($key, $this->later) && 2 === $this->looks[$key] = ($this->looks[$key] ?? 0) + 1) {
                    $this->save(parent::getItem($key)->set($this->later[$key]));
                    unset($this->later[$key]);
                    $this->leader?->release();
                }

                return $item;
            }
        };

        $this->write($this->pool($this->redis, rulesCache: false), 'item', ['tag1']);
        $this->appendRule('tag1');
        [$key, $entry] = $this->electedEntry();
        $medium->later[$key] = $entry;

        $flag = SharedRules::of($medium, self::RULES_KEY, $this->redis);
        self::assertNotNull($flag);
        self::assertTrue($flag->lead());
        $medium->leader = $flag;

        try {
            $cold = $this->pool($this->other, rulesCache: $medium);
            $began = microtime(true);

            self::assertFalse($cold->getItem('item')->isHit(), 'the elected worker\'s set holds the rule');
            self::assertLessThan(0.2, microtime(true) - $began, 'taken once the flag was free, not after the budget');
            self::assertSame([], $this->other->starts, 'and nothing was fetched for it');
            self::assertFalse($flag->isRefreshing(), 'the elected worker had let go before the last look');
        } finally {
            $flag->release();
        }
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

    public function testAnApcuEntryOfAnotherFormatIsNotAdopted(): void
    {
        $default = SharedRules::of(null, self::RULES_KEY, $this->plain);

        if (null === $default) {
            self::markTestSkipped('APCu is not enabled for this SAPI (apc.enable_cli).');
        }

        $rules = new InvalidationRules(self::NAMESPACE.':', 60_000);
        $rules->absorb(['1000-0' => ['t' => '{"t":["a"]}', 'first' => '1']]);

        try {
            self::assertTrue($default->store($rules, microtime(true) * 1000));
            self::assertNotNull($default->load(self::NAMESPACE.':', 60_000), 'the current format is adopted');

            // the same entry, as an earlier version would have labelled it
            foreach (new \APCUIterator('/'.preg_quote($default->getKey(), '/').'$/') as $key => $entry) {
                self::assertIsArray($entry);
                self::assertIsArray($entry['value']);
                apcu_store($key, ['format' => 1] + $entry['value']);
            }

            self::assertNull($default->load(self::NAMESPACE.':', 60_000), 'an entry of another format is a miss');
        } finally {
            $default->forget();
        }
    }

    public function testTheStoredFormatIsPinned(): void
    {
        $default = SharedRules::of(null, self::RULES_KEY, $this->plain);

        if (null === $default) {
            self::markTestSkipped('APCu is not enabled for this SAPI (apc.enable_cli).');
        }

        $rules = new InvalidationRules(self::NAMESPACE.':', 2_592_000_000);
        $rules->absorb([
            '1000-0' => ['t' => '{"t":["a"]}', 'first' => '1'],
            '2000-5' => ['t' => '{"t":["b"],"h":1}', 'first' => '0'],
            '3000-0' => ['t' => '{"p":"shared:x"}', 'first' => '0'],
        ]);

        try {
            self::assertTrue($default->store($rules, 12.5));

            $stored = null;

            foreach (new \APCUIterator('/'.preg_quote($default->getKey(), '/').'$/') as $entry) {
                self::assertIsArray($entry);
                $stored = $entry['value'];
            }

            self::assertSame([
                'format' => 3,
                'rules' => [
                    'tags' => ['a' => 1_048_576_000, 'b' => 2_097_152_005],
                    'hard' => ['b' => 2_097_152_005],
                    'prefixes' => [[3_145_728_000, 'shared:x']],
                    'last' => '3000-0',
                    'first' => '1000-0',
                    'first_opened' => true,
                ],
                'read_at_ms' => 12.5,
            ], $stored, 'the stored shape changed: bump SharedRules::FORMAT, or an older worker reads it as its own');
        } finally {
            $default->forget();
        }
    }

    public function testAPoolMediumStillChecksEveryRule(): void
    {
        $shared = $this->sharedRules();
        $rules = new InvalidationRules(self::NAMESPACE.':', 60_000);
        $rules->absorb(['1000-0' => ['t' => '{"t":["a"]}', 'first' => '1']]);
        self::assertTrue($shared->store($rules, microtime(true) * 1000));

        // another writer of the pool leaves a rule that is not an integer
        $item = $this->shared->getItem($shared->getKey());
        $entry = $item->get();
        self::assertIsArray($entry);
        self::assertIsArray($entry['rules']);
        $entry['rules']['tags'] = ['a' => 'not a rule'];
        $this->shared->save($item->set($entry));

        self::assertNull($shared->load(self::NAMESPACE.':', 60_000));
    }

    /**
     * A set this server shared $ageMs ago, holding this pool's own rule, and
     * a rule another server appended since: what a worker adopting it lacks.
     *
     * @return array{string, array{InvalidationRules, float}} the id the set ends at, and the entry as stored
     */
    private function shareASetAged(int $ageMs): array
    {
        $first = $this->pool($this->redis, 50);
        $this->write($first, 'item1', ['tag1']);
        $first->invalidateTags(['tag2']);
        self::assertTrue($first->getItem('item1')->isHit());
        $last = $this->streamHead();

        // the same set, stamped as read $ageMs ago
        $shared = $this->sharedRules();
        $stored = $shared->load(self::NAMESPACE.':', 60_000);
        self::assertNotNull($stored);
        self::assertSame($last, $stored[0]->last());
        self::assertTrue($shared->store($stored[0], microtime(true) * 1000 - $ageMs));

        $this->appendRule('tag1');

        $aged = $shared->load(self::NAMESPACE.':', 60_000);
        self::assertNotNull($aged);

        return [$last, $aged];
    }

    /**
     * @param array{InvalidationRules, float} $entry
     */
    private function assertSharedEntryIs(array $entry): void
    {
        $shared = $this->sharedRules()->load(self::NAMESPACE.':', 60_000);

        self::assertNotNull($shared);
        self::assertSame([$entry[0]->last(), $entry[1]], [$shared[0]->last(), $shared[1]], 'the shared entry is left to the elected worker');
    }

    /**
     * The entry the elected worker stores - the stream as it is now, stamped
     * as read $ageMs ago - the key it goes under, and the id the set ends at.
     *
     * @return array{string, mixed, string}
     */
    private function electedEntry(int $ageMs = 0): array
    {
        $scratch = new ArrayAdapter();
        $refreshed = SharedRules::of($scratch, self::RULES_KEY, $this->redis);
        self::assertNotNull($refreshed);
        $rules = new InvalidationRules(self::NAMESPACE.':', 2_592_000_000);
        $rules->absorb((array) $this->plain->xRange(self::RULES_KEY, '-', '+'));
        self::assertTrue($refreshed->store($rules, microtime(true) * 1000 - $ageMs));

        return [$refreshed->getKey(), $scratch->getItem($refreshed->getKey())->get(), $rules->last()];
    }

    private function graceMs(): int
    {
        $grace = (new \ReflectionClassConstant(VersionedRedisTagAwareAdapter::class, 'RULES_GRACE_MS'))->getValue();
        self::assertIsInt($grace);

        return $grace;
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
