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
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * How a worker takes the rule set for a read or a write while the set is
 * reloaded, while a reload hangs, and while the stream cannot be read - and
 * what the write after it does.
 *
 * Pools sharing one ArrayAdapter stand for the workers of a server, and a
 * SharedRules on the same medium holding the flag stands for a worker whose
 * reload is in flight. Where the answer depends on what other workers do at
 * the same moment, real processes share one directory instead (see
 * Fixtures/rules-reader.php).
 */
final class RuleSetReloadTest extends TestCase
{
    private const NAMESPACE = 'reload';
    private const RULES_KEY = 'reload:@rules';
    private const ITEM_KEY = 'reload:item';

    private SpyRedis $redis;
    private SpyRedis $other;
    private SpyRedis $third;
    private \Redis $plain;
    private ArrayAdapter $shared;

    /**
     * The set shareCopyAged() shared: the stream before the rule for tag1.
     */
    private InvalidationRules $copy;

    /**
     * The directories the processes of a test shared their set through.
     *
     * @var list<string>
     */
    private array $directories = [];

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

        foreach ($this->directories as $directory) {
            $this->medium($directory)->clear();
            @rmdir($directory.\DIRECTORY_SEPARATOR.'rules');
            @rmdir($directory);
        }
    }

    public function testACopyYoungerThanTheWindowJudgesTheReadWithoutAFetch(): void
    {
        $last = $this->shareCopyAged(0);

        $pool = $this->pool($this->redis, 1_000);

        self::assertTrue($pool->getItem('item')->isHit(), 'judged by the copy, which does not hold the rule yet');
        self::assertSame([], $this->redis->starts, 'with no fetch');

        $exact = $this->pool($this->other, 0);

        self::assertFalse($exact->getItem('item')->isHit(), 'an exact read takes no copy for fresh');
        self::assertSame([$last], $this->other->starts, 'it reloads, the flag being free');
    }

    public function testDuringAnOutageTheCopyJudgesWhateverItsAgeAndWithNoneEveryItemIsAMiss(): void
    {
        $this->shareCopyAged(10_000);
        $this->outageEnded(0);

        $pool = $this->pool($this->redis);

        self::assertTrue($pool->getItem('item')->isHit(), 'judged by a copy ten seconds old');
        self::assertSame([], $this->redis->starts, 'and nothing fetched');

        // a worker retrying holds the flag: the outage still decides
        $flag = $this->otherWorkerReloads();

        try {
            self::assertTrue($this->pool($this->third)->getItem('item')->isHit(), 'judged by the copy while another worker retries');
            self::assertSame([], $this->third->starts);
        } finally {
            $flag->release();
        }

        $before = $this->plain->hGetAll(self::ITEM_KEY);
        $empty = new ArrayAdapter();
        $this->outageEnded(0, $empty);
        $cold = $this->pool($this->other, rulesCache: $empty);

        self::assertSame('computed', $cold->get('item', static fn (): string => 'computed'), 'with no copy, a miss computed for the caller');
        self::assertSame([], $this->other->starts);
        self::assertSame($before, $this->plain->hGetAll(self::ITEM_KEY), 'and not stored');
    }

    public function testAnOutageIsRetriedByOneWorkerASecondAfterTheLastAttemptEnded(): void
    {
        $this->shareCopyAged(10_000);

        foreach ([$this->redis, $this->other] as $redis) {
            $redis->rangesFail = true;
            $redis->failAfterMs = 300;
        }

        $first = $this->pool($this->redis);
        $second = $this->pool($this->other);

        // the flag is free: the first worker reloads, and its failure starts the outage
        self::assertTrue($first->getItem('item')->isHit(), 'the copy it holds');
        self::assertCount(1, $this->redis->starts);
        $endedAt = microtime(true);

        // 1.1 s after that attempt began, 0.8 s after it ended
        self::sleepUntil($endedAt + 0.8);
        self::assertTrue($second->getItem('item')->isHit());
        self::assertSame([], $this->other->starts, 'the retry is counted from the end of the last attempt');

        self::sleepUntil($endedAt + 1.05);
        self::assertTrue($second->getItem('item')->isHit());
        self::assertCount(1, $this->other->starts, 'one worker retries');
        self::assertTrue($first->getItem('item')->isHit());
        self::assertCount(1, $this->redis->starts, 'and only one');
        self::assertTrue($this->sharedRules()->lastAttempt()[0], 'the outage goes on');
    }

    public function testASuccessfulRetryEndsTheOutageForTheServer(): void
    {
        $this->shareCopyAged(10_000);
        $this->outageEnded(1_100);

        self::assertFalse($this->pool($this->redis)->getItem('item')->isHit(), 'the retry reads the rule');
        self::assertCount(1, $this->redis->starts);
        self::assertFalse($this->sharedRules()->lastAttempt()[0], 'and ends the outage');

        self::assertFalse($this->pool($this->other)->getItem('item')->isHit(), 'another worker adopts what it read');
        self::assertSame([], $this->other->starts);
    }

    public function testExactReadsAndCopiesBehindAnInvalidationMadeHereAreJudgedByTheCopyDuringAnOutage(): void
    {
        $this->shareCopyAged(0);
        $this->outageEnded(0);

        self::assertTrue($this->pool($this->redis, 0)->getItem('item')->isHit(), 'an exact read, judged by the copy');
        self::assertSame([], $this->redis->starts, 'it fetches nothing on its own');

        // another worker of this server invalidates the item's tag
        self::assertTrue($this->pool($this->third)->invalidateTags(['tag1']));

        self::assertTrue($this->pool($this->other, 1_000)->getItem('item')->isHit(), 'a copy behind it is judged by until the stream answers');
        self::assertSame([], $this->other->starts);
    }

    public function testWithoutASharedSetTheOutageIsTheProcesss(): void
    {
        $writer = $this->pool($this->third, 50, false);
        $this->write($writer, 'item', ['tag1']);

        $pool = $this->pool($this->redis, 50, false);
        self::assertTrue($pool->getItem('item')->isHit());
        usleep(60_000);
        $this->redis->forgetRanges();
        $this->redis->rangesFail = true;

        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($pool->getItem('item')->isHit(), 'the copy it holds');
        }

        self::assertCount(1, $this->redis->starts, 'one attempt, not one per read');

        $this->other->rangesFail = true;
        self::assertFalse($this->pool($this->other, 50, false)->getItem('item')->isHit());
        self::assertCount(1, $this->other->starts, 'another process tries for itself');
    }

    public function testAnErrorReplyToARulesFetchIsAFailedFetch(): void
    {
        $this->shareCopyAged(10_000);
        $this->redis->rangesAnswerFalse = true;

        self::assertTrue($this->pool($this->redis)->getItem('item')->isHit(), 'the copy it holds');
        self::assertCount(1, $this->redis->starts);
        self::assertTrue($this->sharedRules()->lastAttempt()[0], 'the server is in an outage');
    }

    public function testAReloadInFlightForMoreThanASecondCountsAsAnOutageForEveryoneElse(): void
    {
        $directory = $this->directory();
        $startAt = self::startAt();
        $last = $this->shareCopyAged(3_000, $directory, $startAt);

        // one worker's reload hangs for 2.5 s; the others read 1.2 s into it
        $reports = $this->readInProcesses($directory, $startAt, [
            ['delayMs' => 0, 'answerAfterMs' => 2_500],
            ['delayMs' => 1_200],
            ['delayMs' => 1_200],
            ['delayMs' => 1_250],
            ['delayMs' => 1_300],
        ]);

        self::assertSame(1, $reports[0]['attempts']);
        self::assertGreaterThan(2_400, $reports[0]['ms'], 'the reloader waits for its fetch');

        foreach (\array_slice($reports, 1) as $report) {
            self::assertTrue($report['hit'], 'judged by a copy three seconds old');
            self::assertSame(0, $report['attempts'], 'no fetch');
            self::assertLessThan(200, $report['ms'], 'no wait');
        }

        self::assertNotSame($last, $this->sharedLast($directory), 'the reload landed');
    }

    public function testDuringAnOutageOneWorkerRetriesAndTheOthersJudgeByTheCopyAtOnce(): void
    {
        $directory = $this->directory();
        $startAt = self::startAt();
        $this->shareCopyAged(3_000, $directory, $startAt);
        $this->outageEnded(1_100, $this->medium($directory));

        $reports = $this->readInProcesses($directory, $startAt, array_fill(0, 6, ['delayMs' => 0, 'failAfterMs' => 400]));

        self::assertSame(1, array_sum(array_column($reports, 'attempts')), 'one attempt for the server');
        self::assertSame(array_fill(0, 6, true), array_column($reports, 'hit'), 'every worker judged by the copy');

        foreach ($reports as $report) {
            if (0 === $report['attempts']) {
                self::assertLessThan(250, $report['ms'], 'those that did not try did not wait');
            }
        }
    }

    public function testWithTheFlagFreeOneWorkerReloadsForTheServerAndSharesTheSet(): void
    {
        $directory = $this->directory();
        $startAt = self::startAt();
        $last = $this->shareCopyAged(300, $directory, $startAt);

        $reports = $this->readInProcesses($directory, $startAt, array_fill(0, 6, ['delayMs' => 0, 'answerAfterMs' => 500]));

        self::assertSame(1, array_sum(array_column($reports, 'attempts')), 'one fetch for the server');

        foreach ($reports as $report) {
            if (1 === $report['attempts']) {
                self::assertFalse($report['hit'], 'the reloader judges by what it read');
                self::assertGreaterThan(450, $report['ms']);
            } else {
                self::assertLessThan(250, $report['ms'], 'nobody else waits');
            }
        }

        self::assertSame($this->streamHead(), $this->sharedLast($directory), 'and the set it read is shared');
        self::assertNotSame($last, $this->streamHead());
    }

    public function testAReloadThatStartsOverReplacesTheSharedSet(): void
    {
        $this->shareCopyAged(0);
        $holder = $this->pool($this->other);
        self::assertTrue($holder->getItem('item')->isHit());

        // the stream is lost
        $this->plain->del(self::RULES_KEY);
        usleep(60_000);

        $this->pool($this->redis)->getItem('item');

        self::assertSame(InvalidationRules::NONE, $this->sharedLast(), 'the set that started over replaced the one the stream lost');

        $this->other->forgetRanges();
        $holder->getItem('item');

        self::assertSame([], $this->other->starts, 'a worker holding the lost set adopts it, read later, rather than reload');
    }

    public function testAFailedReloadStartsAnOutage(): void
    {
        $this->shareCopyAged(10_000);
        $this->redis->rangesFail = true;

        self::assertTrue($this->pool($this->redis)->getItem('item')->isHit(), 'the copy it holds');
        self::assertTrue($this->sharedRules()->lastAttempt()[0]);

        self::assertTrue($this->pool($this->other)->getItem('item')->isHit());
        self::assertSame([], $this->other->starts, 'another worker of the server waits for the retry');
    }

    public function testAWorkerThatTakesTheFlagAfterAnotherWorkerReloadedOrTriedFetchesNothing(): void
    {
        $this->shareCopyAged(10_000);

        // the failure first: the reload's verdict drops the item
        foreach (['tried and failed' => false, 'reloaded' => true] as $what => $reloaded) {
            // at the first look at the flag, another worker's attempt lands
            $medium = new class extends ArrayAdapter {
                public ?\Closure $atFirstLook = null;

                public function getItem(mixed $key): CacheItem
                {
                    if (str_ends_with($key, '_flag') && null !== $land = $this->atFirstLook) {
                        $this->atFirstLook = null;
                        $land();
                    }

                    return parent::getItem($key);
                }
            };

            $this->copyInto($medium, 10_000, $this->copy);
            $other = SharedRules::of($medium, self::RULES_KEY, $this->redis);
            self::assertNotNull($other);
            $rules = $this->streamAsRules();
            $medium->atFirstLook = static function () use ($other, $rules, $reloaded): void {
                $now = microtime(true) * 1000;
                $other->attemptEnded($now - 10, $reloaded, $now);

                if ($reloaded) {
                    $other->store($rules, $now - 10);
                }
            };

            $this->redis->forgetRanges();
            $hit = $this->pool($this->redis, rulesCache: $medium)->getItem('item')->isHit();

            self::assertSame(!$reloaded, $hit, \sprintf('%s: the set that is there now judges the read', $what));
            self::assertSame([], $this->redis->starts, \sprintf('%s: it looked again once it held the flag', $what));
        }
    }

    public function testAnotherWorkerAdoptsTheSetAnInvalidationHereSharedAtOnce(): void
    {
        $media = ['a pool' => new ArrayAdapter()];

        if (SharedRules::apcuEnabled()) {
            $media['APCu'] = null;
        }

        foreach ($media as $medium => $store) {
            $shared = SharedRules::of($store, self::RULES_KEY, $this->redis);
            self::assertNotNull($shared);
            $shared->forget();
            Connection::sweep($this->plain, self::NAMESPACE.'*');
            $this->other->forgetRanges();

            try {
                $this->writeKeptAndGone();
                $invalidator = new VersionedRedisTagAwareAdapter($this->redis, self::NAMESPACE, rulesCacheMs: 60_000, rulesCache: $store);
                $worker = new VersionedRedisTagAwareAdapter($this->other, self::NAMESPACE, rulesCacheMs: 60_000, rulesCache: $store);
                self::assertTrue($invalidator->getItem('kept')->isHit());
                self::assertTrue($worker->getItem('kept')->isHit());

                self::assertTrue($invalidator->invalidateTags(['gone']));

                self::assertTrue($worker->getItem('kept')->isHit(), \sprintf('%s: an item the rule does not reach', $medium));
                self::assertFalse($worker->getItem('gone')->isHit(), \sprintf('%s: the one it reaches', $medium));
                self::assertSame([], $this->other->starts, \sprintf('%s: with the set adopted, not fetched', $medium));
            } finally {
                $shared->forget();
            }
        }
    }

    public function testASetSharedAtOnceReachesTheNextRequestWhenTheInvalidatingOneReadsNoMore(): void
    {
        $this->writeKeptAndGone();
        $invalidator = $this->pool($this->redis, 60_000);
        self::assertTrue($invalidator->getItem('kept')->isHit());

        // it saves, invalidates and redirects: no read after the invalidation
        self::assertTrue($invalidator->invalidateTags(['gone']));
        $invalidator->reset();
        unset($invalidator);

        $next = $this->pool($this->other, 60_000);

        self::assertTrue($next->getItem('kept')->isHit());
        self::assertFalse($next->getItem('gone')->isHit());
        self::assertSame([], $this->other->starts, 'the next request adopts the set, and fetches nothing');
    }

    public function testAWorkerThatSeesTheMarkFindsTheRuleAlreadyShared(): void
    {
        // a medium that lets another worker read the moment the mark lands
        $medium = new class extends ArrayAdapter {
            public ?\Closure $afterMark = null;

            public function save(CacheItemInterface $item): bool
            {
                $saved = parent::save($item);

                if (str_ends_with($item->getKey(), '_written') && null !== $then = $this->afterMark) {
                    $this->afterMark = null;
                    $then();
                }

                return $saved;
            }
        };

        $this->writeKeptAndGone();
        $invalidator = $this->pool($this->redis, 60_000, $medium);
        self::assertTrue($invalidator->getItem('kept')->isHit());
        $worker = $this->pool($this->other, 60_000, $medium);
        self::assertTrue($worker->getItem('kept')->isHit());

        $seen = null;
        $medium->afterMark = function () use ($worker, &$seen): void {
            $seen = [$worker->getItem('gone')->isHit(), \count($this->other->starts)];
        };

        self::assertTrue($invalidator->invalidateTags(['gone']));
        self::assertSame([false, 0], $seen, 'the set that holds the rule is shared before the mark: a miss, and no fetch');
    }

    public function testAfterARuleFromElsewhereLandsInBetweenNothingIsSharedAndTheWorkerReloads(): void
    {
        $this->writeKeptAndGone();
        $this->write(new VersionedRedisTagAwareAdapter($this->plain, self::NAMESPACE, rulesCache: false), 'theirs', ['elsewhere']);
        $invalidator = $this->pool($this->redis, 60_000);
        self::assertTrue($invalidator->getItem('kept')->isHit());
        $held = $this->sharedLast();
        $worker = $this->pool($this->other, 60_000);
        self::assertTrue($worker->getItem('theirs')->isHit());

        // a rule from elsewhere lands before this pool's own
        $this->appendRule('elsewhere');
        self::assertTrue($invalidator->invalidateTags(['gone']));

        self::assertSame($held, $this->sharedLast(), 'nothing is shared');

        self::assertFalse($worker->getItem('theirs')->isHit(), 'the rule from elsewhere is seen');
        self::assertFalse($worker->getItem('gone')->isHit());
        self::assertTrue($worker->getItem('kept')->isHit());
        self::assertSame([$held], $this->other->starts, 'behind: it reloads through the flag');
    }

    public function testALoopOfInvalidationsSharesTheSetOnceAtOnceAndOnceWhenTheRequestEnds(): void
    {
        $medium = new CountingStore();
        $this->writeKeptAndGone();
        $this->write(new VersionedRedisTagAwareAdapter($this->plain, self::NAMESPACE, rulesCache: false), 'last', ['loop-4']);
        $invalidator = $this->pool($this->redis, 60_000, $medium);
        self::assertTrue($invalidator->getItem('kept')->isHit());
        $stores = $medium->stores;

        // a batch job reading and invalidating in turn
        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($invalidator->getItem('kept')->isHit());
            self::assertTrue($invalidator->invalidateTags(['loop-'.$i]));
        }

        self::assertSame($stores + 1, $medium->stores, 'the first call shares at once; the later ones, and the reads, do not');

        $invalidator->reset();

        self::assertSame($stores + 2, $medium->stores, 'the end of the request shares the rest, once');
        self::assertSame($this->streamHead(), $this->sharedLast($medium));

        $worker = $this->pool($this->other, 60_000, $medium);

        self::assertTrue($worker->getItem('kept')->isHit());
        self::assertFalse($worker->getItem('last')->isHit(), 'the last rule of the loop is in the set it adopts');
        self::assertSame([], $this->other->starts, 'with no fetch');
    }

    public function testMidLoopAnotherWorkerIsBehindAndReloadsThroughTheFlag(): void
    {
        $this->writeKeptAndGone();
        $this->write(new VersionedRedisTagAwareAdapter($this->plain, self::NAMESPACE, rulesCache: false), 'second', ['loop-1']);
        $invalidator = $this->pool($this->redis, 60_000);
        self::assertTrue($invalidator->getItem('kept')->isHit());
        $worker = $this->pool($this->other, 60_000);
        self::assertTrue($worker->getItem('second')->isHit());

        self::assertTrue($invalidator->invalidateTags(['loop-0']));
        self::assertTrue($invalidator->invalidateTags(['loop-1']));

        self::assertFalse($worker->getItem('second')->isHit(), 'the second rule is not shared yet, and is seen all the same');
        self::assertTrue($worker->getItem('kept')->isHit());
        self::assertCount(1, $this->other->starts, 'behind: it reloads through the flag');
    }

    public function testTheFirstInvalidationAfterAResetSharesAtOnceAgain(): void
    {
        $medium = new CountingStore();
        $this->writeKeptAndGone();
        $invalidator = $this->pool($this->redis, 60_000, $medium);
        self::assertTrue($invalidator->getItem('kept')->isHit());

        self::assertTrue($invalidator->invalidateTags(['first-request']));
        $invalidator->reset();
        $stores = $medium->stores;

        self::assertTrue($invalidator->invalidateTags(['next-request']));

        self::assertSame($stores + 1, $medium->stores, 'the next request\'s first invalidation shares at once');
    }

    public function testAPoolThatGoesAwayAfterItsInvalidationsSharesThem(): void
    {
        $medium = new CountingStore();
        $this->writeKeptAndGone();
        $this->write(new VersionedRedisTagAwareAdapter($this->plain, self::NAMESPACE, rulesCache: false), 'second', ['loop-1']);
        $invalidator = $this->pool($this->redis, 60_000, $medium);
        self::assertTrue($invalidator->getItem('kept')->isHit());

        // it saves, invalidates twice and redirects
        self::assertTrue($invalidator->invalidateTags(['loop-0']));
        self::assertTrue($invalidator->invalidateTags(['loop-1']));
        $stores = $medium->stores;
        unset($invalidator);
        gc_collect_cycles();

        self::assertSame($stores + 1, $medium->stores, 'going away, it shares the set its second rule went into');

        $next = $this->pool($this->other, 60_000, $medium);

        self::assertFalse($next->getItem('second')->isHit());
        self::assertSame([], $this->other->starts, 'the next request adopts it, and fetches nothing');
    }

    public function testACopyBehindAnInvalidationMadeHereReloadsThroughTheFlag(): void
    {
        $this->shareCopyAged(0);
        $pool = $this->pool($this->redis, 1_000);
        self::assertTrue($pool->getItem('item')->isHit());

        // another worker of this server invalidates; the copy is behind it
        self::assertTrue($this->pool($this->third)->invalidateTags(['tag2']));
        $this->redis->forgetRanges();
        $flag = $this->otherWorkerReloads();

        try {
            self::assertFalse($pool->getItem('item')->isHit(), 'a miss while another worker reloads');
            self::assertSame([], $this->redis->starts, 'not a fetch of its own');
        } finally {
            $flag->release();
        }

        self::assertFalse($pool->getItem('item')->isHit(), 'with the flag free, it reloads');
        self::assertCount(1, $this->redis->starts);
    }

    public function testAPoolThatRefusesTheFlagMakesTheWorkerAServerOfOne(): void
    {
        $this->appendRule('first');
        $elsewhere = new VersionedRedisTagAwareAdapter($this->plain, self::NAMESPACE, rulesCache: false);
        $this->write($elsewhere, 'item', ['tag1']);

        // a pool on a full or read-only disk
        $refusing = new class extends ArrayAdapter {
            public function save(CacheItemInterface $item): bool
            {
                return false;
            }
        };
        $logger = new WarningCounter();
        $pool = $this->pool($this->redis, 50, $refusing);
        $pool->setLogger($logger);

        self::assertTrue($pool->getItem('item')->isHit(), 'judged after a fetch of its own');
        self::assertTrue($pool->getItem('item')->isHit());
        self::assertCount(1, $this->redis->starts, 'and judged by it within the window');

        // writes are stamped from the set it fetched, and land
        $head = $this->streamHead();
        $pool->get('computed', static fn (): string => 'computed');
        self::assertTrue($pool->save($pool->getItem('item')->set('saved')));
        self::assertTrue($pool->save($elsewhere->getItem('plain')->set('written')));

        foreach (['computed', 'item', 'plain'] as $key) {
            self::assertSame($head, $this->plain->hGet(self::NAMESPACE.':'.$key, 'm'), \sprintf('%s: stamped from the set it fetched', $key));
        }

        self::assertSame('saved', $elsewhere->getItem('item')->get());

        // past the window it fetches again, warned once
        $fetches = \count($this->redis->starts);
        usleep(60_000);
        self::assertTrue($pool->getItem('item')->isHit());
        self::assertCount($fetches + 1, $this->redis->starts);
        self::assertSame(1, $logger->warnings, 'one warning for the worker');

        // its outage is its own: one attempt, then the set it holds
        $fetches = \count($this->redis->starts);
        usleep(60_000);
        $this->redis->rangesFail = true;

        for ($i = 0; $i < 5; ++$i) {
            self::assertTrue($pool->getItem('item')->isHit());
        }

        self::assertCount($fetches + 1, $this->redis->starts, 'one attempt, not one per read');

        // it shares nothing, not even the set its own rule went into
        $holder = $this->pool($this->third, 60_000, $refusing);
        $holderLogger = new WarningCounter();
        $holder->setLogger($holderLogger);
        self::assertTrue($holder->getItem('item')->isHit());
        self::assertTrue($holder->invalidateTags(['own']));
        self::assertTrue($holder->getItem('item')->isHit());
        self::assertSame(1, $holderLogger->warnings, 'the store is not offered its set');
    }

    public function testAnApcuThatRefusesTheFlagMakesTheWorkerAServerOfOne(): void
    {
        if (!\function_exists('apcu_add')) {
            self::markTestSkipped('APCu is not loaded.');
        }

        $this->appendRule('first');
        $this->write(new VersionedRedisTagAwareAdapter($this->plain, self::NAMESPACE, rulesCache: false), 'item', ['tag1']);

        $process = proc_open([\PHP_BINARY, '-d', 'apc.enable_cli=1', __DIR__.'/Fixtures/refusing-apcu-reader.php', self::NAMESPACE], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['REDIS_DSN' => Connection::dsn()] + getenv());
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $report = json_decode(trim($output), true);
        self::assertIsArray($report, $output.$errors);

        self::assertTrue($report['apcu'] ?? null, 'the set is kept in APCu');
        self::assertSame([true, true, true], $report['hits'] ?? null, 'judged after a fetch of its own');
        self::assertIsInt($report['attempts'] ?? null);
        self::assertGreaterThanOrEqual(2, $report['attempts'], 'one in the window, again past it');
        self::assertTrue($report['saved'] ?? null);
        self::assertSame(1, $report['warnings'] ?? null, 'one warning for the worker');
        self::assertSame($this->streamHead(), $this->plain->hGet(self::NAMESPACE.':written', 'm'), 'stamped from the set it fetched');
    }

    public function testAStoreThatRefusesTheRuleSetIsWarnedOfOnce(): void
    {
        $this->appendRule('first');
        $this->write(new VersionedRedisTagAwareAdapter($this->plain, self::NAMESPACE, rulesCache: false), 'item', ['tag1']);

        // the flag and the attempt record fit; the rule set does not
        $refusing = new class extends ArrayAdapter {
            public function save(CacheItemInterface $item): bool
            {
                $entry = $item->get();

                return (!\is_array($entry) || !\array_key_exists('rules', $entry)) && parent::save($item);
            }
        };
        $logger = new WarningCounter();
        $pool = $this->pool($this->redis, 50, $refusing);
        $pool->setLogger($logger);

        self::assertTrue($pool->getItem('item')->isHit(), 'the reload judges this worker\'s read');
        self::assertCount(1, $this->redis->starts, 'through the flag');
        self::assertSame(1, $logger->warnings, 'the refused set is warned of');

        usleep(60_000);
        self::assertTrue($pool->getItem('item')->isHit());
        self::assertCount(2, $this->redis->starts, 'refused again');
        self::assertSame(1, $logger->warnings, 'and not warned of again');
    }

    public function testANewerSetAlreadySharedIsNoRefusal(): void
    {
        // a medium where, as this pool shares its set, another worker has
        // just shared a newer one
        $medium = new class extends ArrayAdapter {
            public ?\Closure $beforeShare = null;

            public function getItem(mixed $key): CacheItem
            {
                if (null !== $share = $this->beforeShare) {
                    $this->beforeShare = null;
                    $share();
                }

                return parent::getItem($key);
            }
        };

        $this->appendRule('first');
        $this->write(new VersionedRedisTagAwareAdapter($this->plain, self::NAMESPACE, rulesCache: false), 'item', ['tag1']);
        $logger = new WarningCounter();
        $pool = $this->pool($this->redis, 60_000, $medium);
        $pool->setLogger($logger);
        self::assertTrue($pool->getItem('item')->isHit());

        $medium->beforeShare = function () use ($medium): void {
            $this->appendRule('later');
            $this->copyInto($medium, 0);
        };

        self::assertTrue($pool->invalidateTags(['own']));
        self::assertNull($medium->beforeShare, 'the set was offered to the store');
        self::assertSame($this->streamHead(), $this->sharedLast($medium), 'the newer set stays');
        self::assertSame(0, $logger->warnings, 'and nothing is warned of');
    }

    public function testWhileAnotherWorkerReloadsACopyUpToASecondPastTheWindowJudgesTheRead(): void
    {
        $directory = $this->directory();
        $startAt = self::startAt();
        $this->shareCopyAged(300, $directory, $startAt);

        // the reloader takes 0.8 s; the others read 150 ms into it
        $reports = $this->readInProcesses($directory, $startAt, [
            ['delayMs' => 0, 'answerAfterMs' => 800],
            ['delayMs' => 150, 'get' => true],
            ['delayMs' => 150, 'get' => true],
            ['delayMs' => 200, 'get' => true],
        ]);

        self::assertSame(1, $reports[0]['attempts']);

        foreach (\array_slice($reports, 1) as $report) {
            self::assertTrue($report['hit'], 'judged by the copy');
            self::assertSame(0, $report['attempts']);
            self::assertLessThan(150, $report['ms'], 'no wait');
        }
    }

    public function testWhileAnotherWorkerReloadsExactReadsAndCopiesBehindAreJudgedOnlyByAYoungCopy(): void
    {
        $this->shareCopyAged(500);
        $flag = $this->otherWorkerReloads();

        try {
            self::assertTrue($this->pool($this->redis, 0)->getItem('item')->isHit(), 'an exact read, judged by a copy half a second old');

            self::assertTrue($this->pool($this->third)->invalidateTags(['tag2']));
            self::assertFalse($this->pool($this->other, 1_000)->getItem('item')->isHit(), 'a copy behind an invalidation made here is not judged by');
            self::assertSame([], $this->redis->starts);
            self::assertSame([], $this->other->starts);
        } finally {
            $flag->release();
        }
    }

    public function testWhileAnotherWorkerReloadsAYoungCopyBehindAnInvalidationMissesWithoutTheLock(): void
    {
        $this->shareCopyAged(0);
        $locks = 0;
        $computes = 0;
        $pool = $this->pool($this->redis, 60_000);
        $pool->setCallbackWrapper(static function (callable $callback, ItemInterface $item, bool &$save) use (&$locks): mixed {
            ++$locks;

            return $callback($item, $save);
        });
        self::assertTrue($pool->getItem('item')->isHit());

        // another worker of this server invalidates: the young copy is behind
        self::assertTrue($this->pool($this->third)->invalidateTags(['tag2']));
        $flag = $this->otherWorkerReloads();

        try {
            $pool->get('item', static function () use (&$computes): string {
                ++$computes;

                return 'computed';
            });
        } finally {
            $flag->release();
        }

        self::assertSame(1, $computes, 'a miss');
        self::assertSame(0, $locks, 'computed without the lock');
        self::assertSame([], $this->redis->starts);
    }

    public function testWhileAnotherWorkerReloadsAnOlderCopyMissesWithoutTheLockAndStoresStampedFromIt(): void
    {
        $directory = $this->directory();
        $startAt = self::startAt();
        $last = $this->shareCopyAged(3_000, $directory, $startAt);
        $epoch = $this->plain->hGet(self::ITEM_KEY, 'e');

        $reports = $this->readInProcesses($directory, $startAt, [
            ['delayMs' => 0, 'answerAfterMs' => 800],
            ['delayMs' => 150, 'get' => true],
            ['delayMs' => 150, 'get' => true],
            ['delayMs' => 200, 'get' => true],
        ]);

        foreach (\array_slice($reports, 1) as $report) {
            self::assertFalse($report['hit'], 'a miss');
            self::assertFalse($report['locked'], 'computed without the lock');
            self::assertSame(0, $report['attempts']);
            self::assertLessThan(150, $report['ms'], 'no wait');
        }

        self::assertSame('computed', $this->storedValue(), 'what it computed is stored');
        self::assertSame($last, $this->plain->hGet(self::ITEM_KEY, 'm'), 'stamped with the last id of the copy, older than the rule since');
        self::assertNotSame($epoch, $this->plain->hGet(self::ITEM_KEY, 'e'));
    }

    public function testWhileAnotherWorkerReloadsWithNoCopyEveryItemIsAMissAndNothingIsStored(): void
    {
        $directory = $this->directory();
        $this->write($this->pool($this->third, 50, false), 'item', ['tag1']);
        $before = $this->plain->hGetAll(self::ITEM_KEY);

        $reports = $this->readInProcesses($directory, self::startAt(), [
            ['delayMs' => 0, 'answerAfterMs' => 800],
            ['delayMs' => 150, 'get' => true],
            ['delayMs' => 150, 'get' => true],
            ['delayMs' => 200, 'get' => true],
        ]);

        foreach (\array_slice($reports, 1) as $report) {
            self::assertFalse($report['hit'], 'a miss');
            self::assertFalse($report['locked'], 'computed without the lock');
            self::assertSame(0, $report['attempts']);
            self::assertLessThan(150, $report['ms'], 'no wait');
        }

        self::assertSame($before, $this->plain->hGetAll(self::ITEM_KEY), 'nothing stored');
    }

    public function testDuringAnOutageWithNoCopyAMissDoesNotQueueBehindTheLock(): void
    {
        $directory = $this->directory();
        $startAt = self::startAt();
        $this->write($this->pool($this->third, 50, false), 'item', ['tag1']);
        $before = $this->plain->hGetAll(self::ITEM_KEY);
        $this->outageEnded(0, $this->medium($directory));

        // another process holds the lock for the key until 0.7 s in
        [$lockFile, $release] = $this->lockHeldUntil($startAt + 0.7);
        $reports = $this->readInProcesses($directory, $startAt, [
            ['delayMs' => 0, 'get' => true, 'failAfterMs' => 0],
        ], lockFile: $lockFile, meanwhile: $release);

        self::assertFalse($reports[0]['hit'], 'a miss, computed');
        self::assertFalse($reports[0]['locked'], 'without the lock');
        self::assertLessThan(400, $reports[0]['ms'], 'not queued behind its holder');
        self::assertSame($before, $this->plain->hGetAll(self::ITEM_KEY), 'and not stored');
    }

    public function testDuringAnOutageWithACopyAMissStillTakesTheLock(): void
    {
        $directory = $this->directory();
        $startAt = self::startAt();
        $last = $this->shareCopyAged(3_000, $directory, $startAt);
        $this->outageEnded(0, $this->medium($directory));

        // absent: the copy judges a miss
        $this->plain->del(self::ITEM_KEY);

        [$lockFile, $release] = $this->lockHeldUntil($startAt + 0.7);
        $reports = $this->readInProcesses($directory, $startAt, [
            ['delayMs' => 0, 'get' => true, 'failAfterMs' => 0],
        ], lockFile: $lockFile, meanwhile: $release);

        self::assertFalse($reports[0]['hit']);
        self::assertTrue($reports[0]['locked'], 'computed under the lock');
        self::assertGreaterThan(500, $reports[0]['ms'], 'behind its holder');
        self::assertSame($last, $this->plain->hGet(self::ITEM_KEY, 'm'), 'and stored, stamped from the copy');
    }

    public function testWithoutASharedSetAnOutageWithNoCopyComputesWithoutTheLock(): void
    {
        $locks = 0;
        $lock = static function (callable $callback, ItemInterface $item, bool &$save) use (&$locks): mixed {
            ++$locks;

            return $callback($item, $save);
        };
        $computes = 0;
        $compute = static function () use (&$computes): string {
            ++$computes;

            return 'computed';
        };

        // no set held, and the stream cannot be read: at the outage's start, and during it
        $cold = $this->pool($this->redis, 50, false);
        $cold->setCallbackWrapper($lock);
        $this->redis->rangesFail = true;
        $cold->get('item', $compute);
        $cold->get('item', $compute);

        self::assertSame(2, $computes);
        self::assertSame(0, $locks, 'no set held: computed without the lock');

        // a set held when the stream goes
        $this->appendRule('first');
        $held = $this->pool($this->other, 50, false);
        $held->setCallbackWrapper($lock);
        $this->write($held, 'present', ['tag1']);
        usleep(60_000);
        $this->other->rangesFail = true;

        self::assertTrue($held->getItem('present')->isHit(), 'its reload fails: the set held judges the read');
        $held->get('item', $compute);

        self::assertSame(3, $computes);
        self::assertSame(1, $locks, 'a set held: the miss it judges takes the lock');
    }

    public function testAWriteAfterAReadJudgedByTheCopyIsGuardedAndStampedFromIt(): void
    {
        $last = $this->shareCopyAged(0);
        $pool = $this->pool($this->redis, 1_000);

        $item = $pool->getItem('fresh');
        $this->appendRule('tag3');

        self::assertTrue($pool->save($item->set('v')->tag('tag3')));
        self::assertSame($last, $this->plain->hGet(self::NAMESPACE.':fresh', 'm'), 'stamped with the last id of the copy that judged the read');
        self::assertFalse($this->pool($this->other, 0)->getItem('fresh')->isHit(), 'so the rule since judges it');

        $item = $pool->getItem('deleted');
        self::assertTrue($this->pool($this->third)->deleteItem('deleted'));

        self::assertFalse($pool->save($item->set('v')), 'a delete since the read refuses it');
    }

    public function testAPlainWriteTakesItsCopyThroughTheReadTableAndNeverReadsTheHead(): void
    {
        $this->appendRule('first');
        $head = $this->streamHead();
        $elsewhere = new VersionedRedisTagAwareAdapter($this->plain, self::NAMESPACE, rulesCache: false);

        // no copy, the flag free: it reloads
        $writer = $this->pool($this->redis, 1_000);
        self::assertTrue($writer->save($elsewhere->getItem('plain')->set('v')));
        self::assertSame(['-'], $this->redis->starts, 'it took the flag and loaded the stream');
        self::assertSame($head, $this->plain->hGet(self::NAMESPACE.':plain', 'm'));

        // an older copy while another worker reloads: stamped from it
        $last = $this->shareCopyAged(3_000);
        $flag = $this->otherWorkerReloads();

        try {
            $writer = $this->pool($this->other);
            self::assertTrue($writer->save($elsewhere->getItem('older')->set('v')));
            self::assertSame([], $this->other->starts);
            self::assertSame($last, $this->plain->hGet(self::NAMESPACE.':older', 'm'), 'stamped with the last id of the copy');
            self::assertNotSame($this->streamHead(), $last);
        } finally {
            $flag->release();
        }

        self::assertSame(0, $this->redis->watermarks + $this->other->watermarks, 'the stream\'s head was never read');

        // no copy at all, while another worker reloads: not stored, and the value it replaces goes
        $empty = new ArrayAdapter();
        $flag = $this->otherWorkerReloads($empty);

        try {
            self::assertTrue($elsewhere->save($elsewhere->getItem('nocopy')->set('stored')));
            self::assertFalse($this->pool($this->third, rulesCache: $empty)->save($elsewhere->getItem('nocopy')->set('v')));
            self::assertSame(['e'], array_keys((array) $this->plain->hGetAll(self::NAMESPACE.':nocopy')), 'a tombstone where the value was');
        } finally {
            $flag->release();
        }
    }

    public function testWithNoCopyOnlyThePoolsOwnComputationAfterItsMissLeavesTheKey(): void
    {
        $writes = [
            'its own computation after its miss' => static fn (VersionedRedisTagAwareAdapter $pool, string $key): mixed => $pool->get($key, static fn (): string => 'computed'),
            'a forced recomputation' => static fn (VersionedRedisTagAwareAdapter $pool, string $key): mixed => $pool->get($key, static fn (): string => 'computed', \INF),
            'a save after a read' => static fn (VersionedRedisTagAwareAdapter $pool, string $key): bool => $pool->save($pool->getItem($key)->set('computed')),
            'a plain write' => fn (VersionedRedisTagAwareAdapter $pool, string $key): bool => $pool->save((new VersionedRedisTagAwareAdapter($this->plain, self::NAMESPACE, rulesCache: false))->getItem($key)->set('computed')),
        ];

        $elsewhere = new VersionedRedisTagAwareAdapter($this->plain, self::NAMESPACE, rulesCache: false);

        foreach ([60 => ['e'], 0 => []] as $window => $deleted) {
            foreach ($writes as $write => $apply) {
                $key = 'k'.md5($write).$window;
                self::assertTrue($elsewhere->save($elsewhere->getItem($key)->set('stored')));

                // no copy, and the stream cannot be read
                $empty = new ArrayAdapter();
                $this->outageEnded(0, $empty);
                $pool = new VersionedRedisTagAwareAdapter($this->redis, self::NAMESPACE, rulesCache: $empty, guardWindowSeconds: $window);
                $apply($pool, $key);

                if ('its own computation after its miss' === $write) {
                    self::assertSame('stored', $elsewhere->getItem($key)->get(), \sprintf('guard window %d, %s: the key is left as it was', $window, $write));
                } else {
                    self::assertSame($deleted, array_keys((array) $this->plain->hGetAll(self::NAMESPACE.':'.$key)), \sprintf('guard window %d, %s: the value it replaces is deleted', $window, $write));
                }
            }
        }

        // carried per key on the write: one commit holds the own computation and another write
        $empty = new ArrayAdapter();
        $this->outageEnded(0, $empty);
        $pool = $this->pool($this->redis, rulesCache: $empty);
        self::assertTrue($elsewhere->save($elsewhere->getItem('own')->set('stored')));
        self::assertTrue($elsewhere->save($elsewhere->getItem('deferred')->set('stored')));

        $pool->get('own', static function () use ($pool): string {
            $pool->saveDeferred($pool->getItem('deferred')->set('computed'));

            return 'computed';
        });

        self::assertSame('stored', $elsewhere->getItem('own')->get(), 'the own computation leaves its key');
        self::assertSame(['e'], array_keys((array) $this->plain->hGetAll(self::NAMESPACE.':deferred')), 'the other write in the same commit deletes its own');
    }

    public function testWhileHealthyAnInvalidationMadeElsewhereIsSeenWithinTheWindowAndASecond(): void
    {
        foreach (['the flag free' => false, 'another worker reloading' => true] as $state => $reloading) {
            $medium = new ArrayAdapter();
            $this->shareCopyAged(1_100, medium: $medium);

            $flag = $reloading ? $this->otherWorkerReloads($medium) : null;

            try {
                self::assertFalse($this->pool($this->redis, 50, $medium)->getItem('item')->isHit(), \sprintf('%s: past the window and a second, a read sees the rule or misses', $state));

                // within that second, the copy may still judge it
                $this->copyInto($medium, 900, $this->copy);
                $hit = $this->pool($this->other, 50, $medium)->getItem('item')->isHit();

                self::assertSame($reloading, $hit, \sprintf('%s: within it, %s', $state, $reloading ? 'the copy judges' : 'the flag is taken and the set reloaded'));
            } finally {
                $flag?->release();
            }
        }
    }

    public function testAnInvalidationMadeOnThisServerIsNeverServedStaleHere(): void
    {
        $this->appendRule('first');
        $writer = $this->pool($this->third, 50, false);
        $this->write($writer, 'item', ['tag1']);

        // a fresh copy shared, then a worker of this server invalidates the item's tag
        $holder = $this->pool($this->redis, 60_000);
        self::assertTrue($holder->getItem('item')->isHit());
        $younger = $this->pool($this->other, 60_000);
        self::assertTrue($younger->getItem('item')->isHit());
        self::assertTrue($this->pool($this->third)->invalidateTags(['tag1']));

        $flag = $this->otherWorkerReloads();

        try {
            self::assertFalse($younger->getItem('item')->isHit(), 'while another worker reloads');
        } finally {
            $flag->release();
        }

        self::assertFalse($holder->getItem('item')->isHit(), 'with the flag free');

        $younger->reset();
        self::assertFalse($younger->getItem('item')->isHit(), 'after a reset');
        self::assertFalse($this->pool($this->other, 60_000)->getItem('item')->isHit(), 'in the next request');
    }

    public function testDuringAnOutageOneRequestPerServerPerSecondFetches(): void
    {
        $this->shareCopyAged(10_000);
        $pools = [];

        foreach ([$this->redis, $this->other, $this->third] as $redis) {
            $redis->rangesFail = true;
            $pools[] = $this->pool($redis);
        }

        $began = microtime(true);

        do {
            foreach ($pools as $pool) {
                self::assertTrue($pool->getItem('item')->isHit());
            }

            usleep(20_000);
        } while (microtime(true) - $began < 2.5);

        $attempts = \count($this->redis->starts) + \count($this->other->starts) + \count($this->third->starts);

        self::assertGreaterThanOrEqual(2, $attempts);
        self::assertLessThanOrEqual(3, $attempts, 'one at the start, then one a second after each failure ended');
    }

    public function testNoValueComputedWithoutACopyIsStoredOverAValidOne(): void
    {
        // a valid value with no epoch, written with the guard off: no epoch check stands for it
        $elsewhere = new VersionedRedisTagAwareAdapter($this->plain, self::NAMESPACE, rulesCache: false, guardWindowSeconds: 0);
        $computes = [
            'its own computation after its miss' => static fn (VersionedRedisTagAwareAdapter $pool): mixed => $pool->get('valid', static fn (): string => 'computed'),
            'a forced recomputation' => static fn (VersionedRedisTagAwareAdapter $pool): mixed => $pool->get('valid', static fn (): string => 'computed', \INF),
            'a save after a read' => static fn (VersionedRedisTagAwareAdapter $pool): bool => $pool->save($pool->getItem('valid')->set('computed')),
            'a plain write' => static fn (VersionedRedisTagAwareAdapter $pool): bool => $pool->save($elsewhere->getItem('valid')->set('computed')),
        ];

        foreach ($computes as $compute => $apply) {
            self::assertTrue($elsewhere->save($elsewhere->getItem('valid')->set('valid')));
            $valid = $this->plain->hGet(self::NAMESPACE.':valid', 'v');
            self::assertIsString($valid);

            // no copy held while another worker reloads
            $empty = new ArrayAdapter();
            $flag = $this->otherWorkerReloads($empty);

            try {
                $apply($this->pool($this->redis, rulesCache: $empty));
            } finally {
                $flag->release();
            }

            self::assertContains($this->plain->hGet(self::NAMESPACE.':valid', 'v'), [$valid, false], \sprintf('%s: the valid value stays, or goes; it is never replaced', $compute));
        }
    }

    public function testAResetKeepsTheCopyAndItsStamp(): void
    {
        $this->appendRule('first');
        $pool = $this->pool($this->redis, 60_000, false);
        $pool->getItem('item');
        self::assertCount(1, $this->redis->starts);

        $pool->reset();
        $pool->getItem('item');

        self::assertCount(1, $this->redis->starts, 'the next read takes the copy it holds, as fresh as before');
    }

    /**
     * An item tagged tag1, the set shared after it - read $ageMs before $at,
     * or before now - and a rule for tag1 appended elsewhere since: a worker
     * judging by that set serves the item, one that reloads does not. Shared
     * through $directory when given, through $medium, or else through the
     * test's own medium. Returns the last id of the set.
     */
    private function shareCopyAged(int $ageMs, ?string $directory = null, ?float $at = null, ?CacheItemPoolInterface $medium = null): string
    {
        $medium ??= null !== $directory ? $this->medium($directory) : $this->shared;

        $this->appendRule('other');
        $writer = new VersionedRedisTagAwareAdapter($this->plain, self::NAMESPACE, rulesCacheMs: 0, rulesCache: false);
        $this->write($writer, 'item', ['tag1']);
        $this->copy = $this->streamAsRules();

        $this->copyInto($medium, $ageMs, $this->copy, $at);
        $this->appendRule('tag1');

        return $this->copy->last();
    }

    /**
     * $rules, or the stream as it is now, stored in $medium as read $ageMs
     * before $at, or before now, in place of whatever is there.
     */
    private function copyInto(CacheItemPoolInterface $medium, int $ageMs, ?InvalidationRules $rules = null, ?float $at = null): void
    {
        $shared = SharedRules::of($medium, self::RULES_KEY, $this->redis);
        self::assertNotNull($shared);
        self::assertTrue($shared->store($rules ?? $this->streamAsRules(), ($at ?? microtime(true)) * 1000 - $ageMs, true));
    }

    private function streamAsRules(): InvalidationRules
    {
        $rules = new InvalidationRules(self::NAMESPACE.':', 2_592_000_000);
        $rules->absorb((array) $this->plain->xRange(self::RULES_KEY, '-', '+'));

        return $rules;
    }

    /**
     * Records in $medium a failed attempt that ended $agoMs ago.
     */
    private function outageEnded(int $agoMs, ?CacheItemPoolInterface $medium = null): void
    {
        $shared = SharedRules::of($medium ?? $this->shared, self::RULES_KEY, $this->redis);
        self::assertNotNull($shared);
        $endedAtMs = microtime(true) * 1000 - $agoMs;
        $shared->attemptEnded($endedAtMs - 10, false, $endedAtMs);
        self::assertTrue($shared->lastAttempt()[0]);
    }

    /**
     * Another worker of the server, holding the flag for its reload; the
     * caller releases it.
     */
    private function otherWorkerReloads(?CacheItemPoolInterface $medium = null): SharedRules
    {
        $flag = SharedRules::of($medium ?? $this->shared, self::RULES_KEY, $this->redis);
        self::assertNotNull($flag);
        self::assertTrue($flag->lead());

        return $flag;
    }

    private function sharedRules(): SharedRules
    {
        $shared = SharedRules::of($this->shared, self::RULES_KEY, $this->redis);
        self::assertNotNull($shared);

        return $shared;
    }

    /**
     * The last id of the set shared through $medium - a pool, or a directory
     * the processes share - or through the test's own medium.
     */
    private function sharedLast(CacheItemPoolInterface|string|null $medium = null): string
    {
        $shared = SharedRules::of(\is_string($medium) ? $this->medium($medium) : $medium ?? $this->shared, self::RULES_KEY, $this->redis);
        self::assertNotNull($shared);
        $loaded = $shared->load(self::NAMESPACE.':', 60_000);
        self::assertNotNull($loaded);

        return $loaded[0]->last();
    }

    private function directory(): string
    {
        return $this->directories[] = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'artigo-reload-'.bin2hex(random_bytes(4));
    }

    private function medium(string $directory): FilesystemAdapter
    {
        return new FilesystemAdapter('rules', 0, $directory);
    }

    /**
     * A lock file this process holds, and what lets go of it at $moment.
     *
     * @return array{string, \Closure(): void}
     */
    private function lockHeldUntil(float $moment): array
    {
        $lockFile = $this->directory().'.lock';
        $handle = fopen($lockFile, 'c');
        self::assertIsResource($handle);
        self::assertTrue(flock($handle, \LOCK_EX));

        return [$lockFile, static function () use ($handle, $lockFile, $moment): void {
            self::sleepUntil($moment);
            flock($handle, \LOCK_UN);
            fclose($handle);
            @unlink($lockFile);
        }];
    }

    /**
     * When processes started now read: late enough for every one to have
     * booted.
     */
    private static function startAt(): float
    {
        return microtime(true) + 1.5;
    }

    /**
     * Runs one worker per entry in a process of its own, each reading the item
     * $delayMs after $startAt - through get() where $get says so - with its
     * rules stream failing after $failAfterMs, or answering after
     * $answerAfterMs, and collects what each did (see Fixtures/rules-reader.php).
     * With $lockFile, a worker's lock waits while another process holds that
     * file; $meanwhile runs once the workers are started.
     *
     * @param list<array{delayMs: int, get?: bool, failAfterMs?: int, answerAfterMs?: int}> $workers
     *
     * @return list<array{attempts: int, hit: bool, ms: float, locked: bool}>
     */
    private function readInProcesses(string $directory, float $startAt, array $workers, int $rulesCacheMs = 50, string $lockFile = '', ?\Closure $meanwhile = null): array
    {
        $processes = [];

        foreach ($workers as $worker) {
            $command = [
                \PHP_BINARY,
                __DIR__.'/Fixtures/rules-reader.php',
                self::NAMESPACE,
                $directory,
                (string) ($startAt + $worker['delayMs'] / 1000),
                (string) $rulesCacheMs,
                ($worker['get'] ?? false) ? 'get' : 'read',
                (string) ($worker['failAfterMs'] ?? -1),
                (string) ($worker['answerAfterMs'] ?? 0),
                $lockFile,
            ];
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['REDIS_DSN' => Connection::dsn()] + getenv());
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }

        if (null !== $meanwhile) {
            $meanwhile();
        }

        $reports = [];

        foreach ($processes as [$process, $pipes]) {
            $output = (string) stream_get_contents($pipes[1]);
            $errors = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            $report = json_decode(trim($output), true);
            self::assertIsArray($report, $output.$errors);
            $attempts = $report['attempts'] ?? null;
            $hit = $report['hit'] ?? null;
            $ms = $report['ms'] ?? null;
            $locked = $report['locked'] ?? null;
            self::assertIsInt($attempts);
            self::assertIsBool($hit);
            self::assertIsBool($locked);
            $reports[] = ['attempts' => $attempts, 'hit' => $hit, 'ms' => \is_int($ms) || \is_float($ms) ? (float) $ms : \INF, 'locked' => $locked];
        }

        return $reports;
    }

    private static function sleepUntil(float $moment): void
    {
        $wait = $moment - microtime(true);

        if ($wait > 0) {
            usleep((int) ($wait * 1_000_000));
        }
    }

    private function pool(SpyRedis $redis, int $rulesCacheMs = 50, CacheItemPoolInterface|false|null $rulesCache = null): VersionedRedisTagAwareAdapter
    {
        return new VersionedRedisTagAwareAdapter($redis, self::NAMESPACE, rulesCacheMs: $rulesCacheMs, rulesCache: $rulesCache ?? $this->shared);
    }

    private function storedValue(): mixed
    {
        return (new VersionedRedisTagAwareAdapter($this->plain, self::NAMESPACE, rulesCacheMs: 60_000, rulesCache: false))->getItem('item')->get();
    }

    /**
     * @param list<string> $tags
     */
    private function write(VersionedRedisTagAwareAdapter $pool, string $key, array $tags): void
    {
        self::assertTrue($pool->save($pool->getItem($key)->set('v')->tag($tags)));
    }

    /**
     * Two items written elsewhere, tagged keep and gone, after a first rule.
     */
    private function writeKeptAndGone(): void
    {
        $this->appendRule('first');
        $elsewhere = new VersionedRedisTagAwareAdapter($this->plain, self::NAMESPACE, rulesCache: false);
        $this->write($elsewhere, 'kept', ['keep']);
        $this->write($elsewhere, 'gone', ['gone']);
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

/**
 * Counts the warnings a pool logs.
 */
final class WarningCounter extends AbstractLogger
{
    public int $warnings = 0;

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (LogLevel::WARNING === $level) {
            ++$this->warnings;
        }
    }
}

/**
 * A pool medium that counts the rule sets stored in it.
 */
final class CountingStore extends ArrayAdapter
{
    public int $stores = 0;

    public function save(CacheItemInterface $item): bool
    {
        $saved = parent::save($item);
        $entry = $item->get();

        if ($saved && \is_array($entry) && \array_key_exists('rules', $entry)) {
            ++$this->stores;
        }

        return $saved;
    }
}
