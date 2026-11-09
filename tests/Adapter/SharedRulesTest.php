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
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/**
 * The rule set shared by the workers of a server: what a fresh pool pays
 * when another one has already loaded the rules, how a rule appended by this
 * process or by another one reaches everybody, and the flag and the record of
 * the last attempt kept beside it.
 *
 * Two pools over two connections, sharing one ArrayAdapter, stand for two
 * workers on one server - the same shape APCu gives them in production,
 * without needing APCu here. The flag lives in the same medium, so a second
 * SharedRules on it stands for another worker taking it. How a read takes the
 * set while it is reloaded is RuleSetReloadTest's.
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

    public function testASetWhoseFetchBeganAFractionOfAMillisecondAfterTheMarkIsNotBehind(): void
    {
        $this->appendRule('first');
        $this->write(new VersionedRedisTagAwareAdapter($this->plain, self::NAMESPACE, rulesCache: false), 'item', ['tag2']);
        $rules = new InvalidationRules(self::NAMESPACE.':', 2_592_000_000);
        $rules->absorb((array) $this->plain->xRange(self::RULES_KEY, '-', '+'));
        [$ms] = InvalidationRules::split($rules->last());

        // a rule of this server, its append returned 0.2 ms into a millisecond;
        // the stream has lost it since
        $atMs = floor(microtime(true) * 1000) + 0.2;
        $this->sharedRules()->written($atMs, ($ms + 1).'-0');

        // a set whose fetch began 0.3 ms later, in the same millisecond
        self::assertTrue($this->sharedRules()->store($rules, $atMs + 0.3, true));

        $pool = $this->pool($this->redis, 60_000);

        self::assertTrue($pool->getItem('item')->isHit());
        self::assertTrue($pool->getItem('item')->isHit());
        self::assertSame([], $this->redis->starts, 'read after the mark: not behind, and not fetched');
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

        self::assertTrue($shared->store($older, 3.0, true), 'a set that started over replaces it');

        $loaded = $shared->load(self::NAMESPACE.':', 60_000);

        self::assertNotNull($loaded);
        self::assertSame(['1000-0', 3.0], [$loaded[0]->last(), $loaded[1]]);
    }

    public function testAColdWorkerElectedDuringAnOutageLoadsTheStreamAndEndsIt(): void
    {
        $this->write($this->pool($this->redis, rulesCache: false), 'item', ['tag1']);
        $this->appendRule('tag1');

        // nothing shared, and the last attempt ended more than a second ago
        $endedAtMs = microtime(true) * 1000 - 1_100;
        $this->sharedRules()->attemptEnded($endedAtMs - 10, false, $endedAtMs);

        $cold = $this->pool($this->other);

        self::assertFalse($cold->getItem('item')->isHit(), 'it tries again, and loads the stream');
        self::assertSame(['-'], $this->other->starts);
        self::assertFalse($this->sharedRules()->lastAttempt()[0], 'which ends the outage');
        self::assertNotNull($this->sharedRules()->load(self::NAMESPACE.':', 60_000), 'and shares what it read');
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
            self::assertFalse($worker->getItem('item')->isHit(), 'the fresher set is adopted, rather than the held one served while the reload runs');
            self::assertSame([], $this->other->starts, 'and nothing was fetched for it');
        } finally {
            $flag->release();
        }
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

    public function testTheFlagIsALeaseHeldByOneWorkerAtATimeInEitherMedium(): void
    {
        foreach ($this->media() as $medium => [$first, $second]) {
            try {
                self::assertNull($second->reloadStartedAt(), \sprintf('%s: free', $medium));

                $before = microtime(true) * 1000;
                self::assertTrue($first->lead(), \sprintf('%s: taken', $medium));
                $after = microtime(true) * 1000;
                self::assertTrue($first->lead(), \sprintf('%s: and held', $medium));
                self::assertFalse($second->lead(), \sprintf('%s: by one worker at a time', $medium));

                $startedAt = $second->reloadStartedAt();
                self::assertNotNull($startedAt, \sprintf('%s: it says when the reload began', $medium));
                self::assertGreaterThanOrEqual(floor($before), $startedAt);
                self::assertLessThanOrEqual($after, $startedAt);

                $first->release();
                self::assertNull($second->reloadStartedAt(), \sprintf('%s: its owner let go', $medium));
                self::assertTrue($second->lead(), \sprintf('%s: and another worker takes it', $medium));
                $second->release();
            } finally {
                $first->forget();
            }
        }
    }

    public function testALapsedLeaseIsTakenOverAndItsOldOwnerCannotReleaseTheNewOne(): void
    {
        foreach ($this->media() as $medium => [$dead, $next]) {
            $third = $this->sameAs($dead);

            try {
                self::assertTrue($dead->lead());

                // its owner stopped long ago: the lease is older than its term
                $this->ageLease($dead, SharedRules::LEASE_MS + 1_000);

                self::assertNull($next->reloadStartedAt(), \sprintf('%s: a lapsed lease is no reload in flight', $medium));
                self::assertTrue($next->lead(), \sprintf('%s: it is taken over', $medium));

                $dead->release();

                self::assertFalse($third->lead(), \sprintf('%s: the old owner let go of nothing', $medium));

                $next->release();

                self::assertTrue($third->lead(), \sprintf('%s: the new owner did', $medium));
                $third->release();
            } finally {
                $dead->forget();
            }
        }
    }

    public function testAWorkerHoldingTheFlagIsNotKeptAliveForItsRelease(): void
    {
        $makers = ['a pool' => fn (): ?SharedRules => SharedRules::of($this->shared, self::RULES_KEY, $this->redis)];

        if (SharedRules::apcuEnabled()) {
            $makers['APCu'] = fn (): ?SharedRules => SharedRules::of(null, self::RULES_KEY, $this->plain);
        }

        foreach ($makers as $medium => $make) {
            $holder = $make();
            self::assertNotNull($holder);
            self::assertTrue($holder->lead());
            $instance = \WeakReference::create($holder);
            unset($holder);
            gc_collect_cycles();

            self::assertNull($instance->get(), \sprintf('%s: gone once nothing else holds it', $medium));

            $make()?->forget();
        }
    }

    public function testAReloaderThatDiesOfAFatalErrorLetsGoOfTheFlag(): void
    {
        $directory = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'artigo-dying-'.bin2hex(random_bytes(4));
        $medium = new FilesystemAdapter('rules', 0, $directory);

        try {
            $process = proc_open([\PHP_BINARY, __DIR__.'/Fixtures/dying-reloader.php', self::RULES_KEY, $directory], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['REDIS_DSN' => Connection::dsn()] + getenv());
            self::assertIsResource($process);
            $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            self::assertStringContainsString('took the flag', $output, $output);

            // the worker's connection is a plain one, and so is the key it shares under
            $flag = SharedRules::of($medium, self::RULES_KEY, $this->plain);
            self::assertNotNull($flag);
            self::assertNull($flag->reloadStartedAt(), 'the flag is free');
        } finally {
            $medium->clear();
            @rmdir($directory.\DIRECTORY_SEPARATOR.'rules');
            @rmdir($directory);
        }
    }

    public function testTheMarkOnlyMovesForwardInEitherMedium(): void
    {
        foreach ($this->media() as $medium => [$shared]) {
            try {
                $shared->forget();
                $shared->written(2_000.0, '2000-5');
                $shared->written(1_000.0, '1000-0');
                self::assertSame([2_000.0, '2000-5'], $shared->writtenMark(), \sprintf('%s: an older mark changes nothing', $medium));

                // half a microsecond in: rounded up to the next one
                $shared->written(3_000.0005, '1500-0');
                self::assertSame([3_000.001, '2000-5'], $shared->writtenMark(), \sprintf('%s: time and id each keep their newest, to the microsecond', $medium));

                // the largest sequence its bits hold, and one too large for them
                $shared->written(3_000.0, '4000-1048575');
                self::assertSame([3_000.001, '4000-1048575'], $shared->writtenMark(), \sprintf('%s: kept as it is', $medium));

                $shared->written(3_000.0, '4000-5000000');
                self::assertSame([3_000.001, '4001-0'], $shared->writtenMark(), \sprintf('%s: stored as the next millisecond, later than the rule', $medium));

                $shared->written(1.0, '4000-1048575');
                self::assertSame([3_000.001, '4001-0'], $shared->writtenMark(), \sprintf('%s: an older write lowers nothing', $medium));

                $shared->forget();
                $shared->written(10.0, InvalidationRules::NONE);
                self::assertSame([10.0, InvalidationRules::NONE], $shared->writtenMark(), \sprintf('%s: the id of an empty stream', $medium));
            } finally {
                $shared->forget();
            }

            self::assertNull($shared->writtenMark(), \sprintf('%s: forgotten with the set', $medium));
        }
    }

    public function testTwoInvalidationsRacingLeaveTheLaterMarkInAPool(): void
    {
        [$idA, $atA, $idB, $atB, $readAt] = $this->twoRules();
        $directory = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'artigo-race-'.bin2hex(random_bytes(4));

        // the pool the other process shares, where B's mark is on its way
        // between A's look at the mark and A's write: a FilesystemAdapter, not
        // a class of its own, whose files would carry another name
        $medium = new HookedPool(new FilesystemAdapter('rules', 0, $directory));

        try {
            $a = SharedRules::of($medium, self::RULES_KEY, $this->redis);
            self::assertNotNull($a);
            self::assertTrue($a->store($this->rulesUpTo($idA), $readAt, true));

            $process = null;
            $pipes = [];
            $medium->beforeSave = static function (CacheItemInterface $item) use (&$process, &$pipes, $directory, $atB, $idB): bool {
                if (!str_ends_with($item->getKey(), '_mark')) {
                    return false;
                }

                $process = proc_open([\PHP_BINARY, __DIR__.'/Fixtures/mark-writer.php', $directory, self::RULES_KEY, \sprintf('%.6F', $atB), $idB], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['REDIS_DSN' => Connection::dsn()] + getenv());

                // long enough for its write to land, were nothing holding it off
                usleep(700_000);

                return true;
            };

            $a->written($atA, $idA);

            self::assertIsResource($process);
            self::assertIsResource($pipes[1] ?? null);
            self::assertIsResource($pipes[2] ?? null);
            $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            self::assertStringContainsString('written', $output, $output);

            self::assertSame([ceil($atB * 1000) / 1000, $idB], $a->writtenMark(), 'the later mark stays');

            // a worker whose set was read between the two rules
            $third = new VersionedRedisTagAwareAdapter($this->other, self::NAMESPACE, rulesCacheMs: 60_000, rulesCache: $medium);

            self::assertFalse($third->getItem('itemB')->isHit(), 'its set lacks the later rule: behind, it reloads');
            self::assertCount(1, $this->other->starts);
        } finally {
            $medium->clear();
            @rmdir($directory.\DIRECTORY_SEPARATOR.'rules');
            @rmdir($directory);
        }
    }

    public function testTwoInvalidationsRacingLeaveTheLaterMarkInApcu(): void
    {
        if (!\function_exists('apcu_add')) {
            self::markTestSkipped('APCu is not loaded.');
        }

        [$idA, $atA, $idB, $atB, $readAt] = $this->twoRules();

        $process = proc_open([\PHP_BINARY, '-d', 'apc.enable_cli=1', __DIR__.'/Fixtures/racing-apcu-marks.php', self::NAMESPACE, $idA, \sprintf('%.6F', $atA), $idB, \sprintf('%.6F', $atB), \sprintf('%.6F', $readAt)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['REDIS_DSN' => Connection::dsn()] + getenv());
        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        $report = json_decode(trim($output), true);
        self::assertIsArray($report, $output.$errors);

        self::assertEquals([ceil($atB * 1000) / 1000, $idB], $report['mark'] ?? null, 'the later mark stays');
        self::assertFalse($report['hit'] ?? null, 'a worker whose set lacks the later rule is behind');
        self::assertSame(1, $report['attempts'] ?? null, 'and reloads');
    }

    public function testTheFlagAndTheNoteHaveNoExpiryInApcu(): void
    {
        $default = SharedRules::of(null, self::RULES_KEY, $this->plain);

        if (null === $default) {
            self::markTestSkipped('APCu is not enabled for this SAPI (apc.enable_cli).');
        }

        $rules = new InvalidationRules(self::NAMESPACE.':', 60_000);
        $rules->absorb(['1000-0' => ['t' => '{"t":["a"]}', 'first' => '1']]);
        $prefix = 'artigo.versioned.'.$default->getKey();

        try {
            self::assertTrue($default->lead());
            $default->written(microtime(true) * 1000, '1000-0');
            $default->written(microtime(true) * 1000, '1001-0');
            self::assertTrue($default->store($rules, microtime(true) * 1000));

            // apcu_cas() keeps the expiry an entry was created with, so a flag
            // or a note created with one would vanish a day later, however
            // often it changed since
            foreach (['_flag', '_mark_at_us', '_mark_id'] as $suffix) {
                $info = apcu_key_info($prefix.$suffix);
                self::assertIsArray($info, $suffix.' is there');
                self::assertSame(0, $info['ttl'], $suffix.' has no expiry');
            }

            $info = apcu_key_info($prefix);
            self::assertIsArray($info, 'the copy is there');
            self::assertSame(SharedRules::TTL_SECONDS, $info['ttl'], 'the copy keeps its expiry');
        } finally {
            $default->release();
            $default->forget();
        }
    }

    public function testTheLastAttemptIsRecordedInEitherMedium(): void
    {
        foreach ($this->media() as $medium => [$shared]) {
            try {
                self::assertSame([false, null, null], $shared->lastAttempt(), \sprintf('%s: none yet', $medium));

                $shared->attemptEnded(1_000.0, false, 1_300.0);
                self::assertSame([true, 1_300.0, null], $shared->lastAttempt(), \sprintf('%s: a failure starts an outage', $medium));

                $shared->attemptEnded(2_000.0, true, 2_100.0);
                self::assertSame([false, 2_100.0, 2_000.0], $shared->lastAttempt(), \sprintf('%s: a success ends it, and says when it began', $medium));

                $shared->attemptEnded(1_500.0, false, 2_200.0);
                self::assertSame([false, 2_100.0, 2_000.0], $shared->lastAttempt(), \sprintf('%s: the failure of a reload that began before the last success is ignored', $medium));

                $shared->attemptEnded(2_050.0, false, 2_300.0);
                self::assertSame([true, 2_300.0, 2_000.0], $shared->lastAttempt(), \sprintf('%s: one that began after it counts', $medium));
            } finally {
                $shared->forget();
            }

            self::assertSame([false, null, null], $shared->lastAttempt(), \sprintf('%s: forgotten with the set', $medium));
        }
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
     * Two rules appended elsewhere, A and then B, and itemB, tagged with B's
     * tag, written before them: the ids and the times of A and B, and a moment
     * between them.
     *
     * @return array{string, float, string, float, float}
     */
    private function twoRules(): array
    {
        $this->appendRule('first');
        $this->write(new VersionedRedisTagAwareAdapter($this->plain, self::NAMESPACE, rulesCache: false), 'itemB', ['tagB']);

        $idA = $this->plain->xAdd(self::RULES_KEY, '*', ['t' => json_encode(['t' => ['tagA']], \JSON_THROW_ON_ERROR), 'first' => '0']);
        $atA = self::asPassed(microtime(true) * 1000);
        usleep(5_000);
        $between = self::asPassed(microtime(true) * 1000);
        usleep(5_000);
        $idB = $this->plain->xAdd(self::RULES_KEY, '*', ['t' => json_encode(['t' => ['tagB']], \JSON_THROW_ON_ERROR), 'first' => '0']);
        $atB = self::asPassed(microtime(true) * 1000);
        self::assertIsString($idA);
        self::assertIsString($idB);

        return [$idA, $atA, $idB, $atB, $between];
    }

    /**
     * $ms as a process given it on its command line reads it back: the same
     * value on both sides.
     */
    private static function asPassed(float $ms): float
    {
        return (float) \sprintf('%.6F', $ms);
    }

    /**
     * The stream up to $id, as a set.
     */
    private function rulesUpTo(string $id): InvalidationRules
    {
        $rules = new InvalidationRules(self::NAMESPACE.':', 2_592_000_000);
        $rules->absorb((array) $this->plain->xRange(self::RULES_KEY, '-', $id));

        return $rules;
    }

    /**
     * Two SharedRules on each medium the test can reach - two workers of one
     * server.
     *
     * @return array<string, array{SharedRules, SharedRules}>
     */
    private function media(): array
    {
        $media = ['a pool' => [$this->sharedRules(), $this->sharedRules()]];

        if (SharedRules::apcuEnabled()) {
            $apcu = SharedRules::of(null, self::RULES_KEY, $this->plain);
            self::assertNotNull($apcu);
            $media['APCu'] = [$apcu, $this->sameAs($apcu)];
        }

        return $media;
    }

    /**
     * Another worker on the medium $shared speaks to.
     */
    private function sameAs(SharedRules $shared): SharedRules
    {
        $same = $shared->isApcu() ? SharedRules::of(null, self::RULES_KEY, $this->plain) : $this->sharedRules();
        self::assertNotNull($same);
        self::assertSame($shared->getKey(), $same->getKey());

        return $same;
    }

    /**
     * Moves the start of the lease $holder holds $ms into the past, as the
     * lease is stored: the start in milliseconds times a million, plus a
     * nonce.
     */
    private function ageLease(SharedRules $holder, int $ms): void
    {
        $key = $holder->getKey().'_flag';

        if ($holder->isApcu()) {
            $lease = apcu_fetch('artigo.versioned.'.$key);
            self::assertIsInt($lease);
            self::assertTrue(apcu_store('artigo.versioned.'.$key, $lease - $ms * 1_000_000));

            return;
        }

        $item = $this->shared->getItem($key);
        $lease = $item->get();
        self::assertIsInt($lease);
        self::assertTrue($this->shared->save($item->set($lease - $ms * 1_000_000)));
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

/**
 * A PSR-6 pool in front of another, running $beforeSave before a save until it
 * answers true.
 */
final class HookedPool implements CacheItemPoolInterface
{
    /** @var (\Closure(CacheItemInterface): bool)|null */
    public ?\Closure $beforeSave = null;

    public function __construct(
        private readonly CacheItemPoolInterface $inner,
    ) {
    }

    public function getItem(string $key): CacheItemInterface
    {
        return $this->inner->getItem($key);
    }

    /**
     * @param array<string> $keys
     *
     * @return iterable<string, CacheItemInterface>
     */
    public function getItems(array $keys = []): iterable
    {
        foreach ($keys as $key) {
            yield $key => $this->inner->getItem($key);
        }
    }

    public function hasItem(string $key): bool
    {
        return $this->inner->hasItem($key);
    }

    public function clear(): bool
    {
        return $this->inner->clear();
    }

    public function deleteItem(string $key): bool
    {
        return $this->inner->deleteItem($key);
    }

    /**
     * @param array<string> $keys
     */
    public function deleteItems(array $keys): bool
    {
        return $this->inner->deleteItems($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        if (null !== $before = $this->beforeSave) {
            if ($before($item)) {
                $this->beforeSave = null;
            }
        }

        return $this->inner->save($item);
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        return $this->inner->saveDeferred($item);
    }

    public function commit(): bool
    {
        return $this->inner->commit();
    }
}
