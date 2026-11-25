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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Exception\InvalidArgumentException;
use Symfony\Component\Cache\Traits\RedisProxy;

/**
 * What a command that fails leaves behind.
 *
 * phpredis 6.3 keeps the socket open after some failures and reads the late
 * reply as the next command's answer, and every later reply one command late,
 * until the socket closes: reads then return other keys' values as hits. The
 * adapter closes the connection where such a reply may be left, and otherwise
 * does what it does without the close: a quick failure is sent again where it
 * was, after a failure that waited out the read timeout nothing of that batch
 * is. With Redis answering, nothing changes.
 *
 * LateReplyRedis stands in for phpredis 6.3's socket, FakeCluster and
 * PipelineCluster for a cluster client.
 */
final class FailedCommandTest extends TestCase
{
    private string $namespace;

    protected function setUp(): void
    {
        $this->namespace = 'failed'.bin2hex(random_bytes(3));
    }

    /**
     * @param \Closure(LateReplyRedis, string): void $stall makes one command time out, its reply left on the socket
     */
    #[DataProvider('commandsThatTimeOut')]
    public function testACommandThatTimesOutLeavesNoLateReplyBehind(\Closure $stall): void
    {
        $redis = new LateReplyRedis();
        $writer = $this->pool($redis);

        foreach (['b', 'c', 'd'] as $key) {
            self::assertTrue($writer->save($writer->getItem($key)->set('value of '.$key)));
        }

        $stall($redis, $this->namespace);
        self::assertSame(0, $redis->stalls, 'the command timed out');

        $reader = $this->pool($redis);

        foreach (['b', 'c', 'd', 'b', 'c', 'd'] as $key) {
            $item = $reader->getItem($key);

            self::assertTrue($item->isHit(), \sprintf('"%s" is read', $key));
            self::assertSame('value of '.$key, $item->get(), 'as its own value');
        }

        self::assertSame(1, $redis->closes, 'the connection was closed once, after the timeout');
    }

    /**
     * @return iterable<string, array{\Closure(LateReplyRedis, string): void}>
     */
    public static function commandsThatTimeOut(): iterable
    {
        yield 'the guarded write after a miss' => [static function (LateReplyRedis $redis, string $namespace): void {
            $pool = new VersionedRedisTagAwareAdapter($redis, $namespace, rulesCache: false);

            self::assertSame('value of a', $pool->get('a', static function () use ($redis): string {
                $redis->stalls = 1;

                return 'value of a';
            }));
        }];

        yield 'the append of a rule' => [static function (LateReplyRedis $redis, string $namespace): void {
            $redis->stalls = 1;

            self::assertFalse((new VersionedRedisTagAwareAdapter($redis, $namespace, rulesCache: false))->invalidateTags(['other']));
        }];

        yield 'a reload of the rules' => [static function (LateReplyRedis $redis, string $namespace): void {
            $pool = new VersionedRedisTagAwareAdapter($redis, $namespace, rulesCacheMs: 0, rulesCache: false);
            $pool->getItem('warm');
            $redis->stalls = 1;
            $redis->stallOn = 'XRANGE';

            $pool->getItem('b');
        }];

        yield 'a reading of the server\'s clock' => [static function (LateReplyRedis $redis, string $namespace): void {
            $pool = new VersionedRedisTagAwareAdapter($redis, $namespace, rulesCache: false, softGraceSeconds: 60);
            self::assertTrue($pool->save($pool->getItem('e')->set('e')->tag('t')));
            self::assertTrue($pool->invalidateTags(['t']));
            $redis->stalls = 1;
            $redis->stallOn = 'TIME';

            self::assertFalse($pool->getItem('e')->isHit());
        }];

        yield 'a walk of the pool' => [static function (LateReplyRedis $redis, string $namespace): void {
            $redis->stalls = 1;
            $redis->stallOn = 'SCAN';

            self::assertFalse((new VersionedRedisTagAwareAdapter($redis, $namespace, rulesCache: false))->prune());
        }];

        yield 'a read of a batch' => [static function (LateReplyRedis $redis, string $namespace): void {
            $redis->stalls = 1;
            $redis->stallOn = 'HMGET';

            foreach ((new VersionedRedisTagAwareAdapter($redis, $namespace, rulesCache: false))->getItems(['b', 'c']) as $item) {
                self::assertFalse($item->isHit());
            }
        }];

        yield 'a check for an item' => [static function (LateReplyRedis $redis, string $namespace): void {
            $pool = new VersionedRedisTagAwareAdapter($redis, $namespace, rulesCache: false);
            $pool->getItem('warm');
            $redis->stalls = 1;
            $redis->stallOn = 'HMGET';

            self::assertFalse($pool->hasItem('b'));
        }];

        yield 'a plain write' => [static function (LateReplyRedis $redis, string $namespace): void {
            $item = (new VersionedRedisTagAwareAdapter($redis, $namespace, rulesCache: false))->getItem('e');
            $pool = new VersionedRedisTagAwareAdapter($redis, $namespace, rulesCache: false);
            $pool->getItem('warm');
            $redis->stalls = 1;
            $redis->stallOn = 'HSETEX';

            self::assertFalse($pool->save($item->set('e')));
        }];

        yield 'a delete' => [static function (LateReplyRedis $redis, string $namespace): void {
            $redis->stalls = 1;
            $redis->stallOn = 'HDEL';

            (new VersionedRedisTagAwareAdapter($redis, $namespace, rulesCache: false))->deleteItem('e');
        }];

        yield 'the drop of an outdated item' => [static function (LateReplyRedis $redis, string $namespace): void {
            $pool = new VersionedRedisTagAwareAdapter($redis, $namespace, rulesCache: false);
            self::assertTrue($pool->save($pool->getItem('e')->set('e')->tag('t')));
            self::assertTrue($pool->invalidateTags(['t']));
            $redis->stalls = 1;
            $redis->stallOn = 'HPEXPIRE';

            self::assertFalse($pool->getItem('e')->isHit());
        }];

        yield 'an unlink, with the guard off' => [static function (LateReplyRedis $redis, string $namespace): void {
            $redis->stalls = 1;
            $redis->stallOn = 'UNLINK';

            (new VersionedRedisTagAwareAdapter($redis, $namespace, rulesCache: false, guardWindowSeconds: 0))->deleteItem('e');
        }];

        yield 'a pipeline that did not go out whole' => [static function (LateReplyRedis $redis, string $namespace): void {
            // exec() answers false without throwing, its socket open
            $redis->execsAnswerFalse = 1;

            (new VersionedRedisTagAwareAdapter($redis, $namespace, rulesCache: false))->deleteItem('e');
        }];
    }

    /**
     * phpredis raises some error replies (OOM, READONLY, LOADING, BUSY,
     * NOPERM), and keeps the reply as its last error: the reply was read to
     * its end, and closing would only reconnect on every refused write.
     */
    public function testAnErrorReplyOfTheServerKeepsTheConnection(): void
    {
        $redis = new LateReplyRedis();
        $pool = $this->pool($redis);
        $pool->getItem('warm');
        $item = $this->pool($redis)->getItem('k');

        $redis->errorReply = "OOM command not allowed when used memory > 'maxmemory'.";

        self::assertFalse($pool->save($item->set('v')));
        self::assertSame(0, $redis->closes, 'the connection stays');
        self::assertTrue($pool->save($item->set('v')), 'and takes the next write');

        $redis->stalls = 1;

        self::assertFalse($pool->save($item->set('w')));
        self::assertSame(1, $redis->closes, 'a failure on the wire closes it');
    }

    /**
     * An error reply that comes with another failure is no proof that the
     * socket is in step: on phpredis 6.3 a pipeline whose first reply timed
     * out reads on, and throws the error reply of a later command with a
     * reply still unread; and an error reply thrown on top of an earlier
     * failure carries it as its previous exception.
     *
     * @param \Closure(LateReplyRedis): void $fail
     */
    #[DataProvider('errorRepliesAfterAFailure')]
    public function testAnErrorReplyAfterAnotherFailureClosesTheConnection(\Closure $fail): void
    {
        $redis = new LateReplyRedis();
        $writer = $this->pool($redis);

        foreach (['b', 'c', 'd'] as $key) {
            self::assertTrue($writer->save($writer->getItem($key)->set('value of '.$key)));
        }

        // what deleteItem() answers after Symfony's retry differs between its
        // lines; what is read afterwards does not
        $fail($redis);
        $this->pool($redis)->deleteItem('e');

        foreach (['b', 'c', 'd', 'b', 'c', 'd'] as $key) {
            $item = $this->pool($redis)->getItem($key);

            self::assertTrue($item->isHit());
            self::assertSame('value of '.$key, $item->get());
        }

        self::assertSame(1, $redis->closes);
    }

    /**
     * @return iterable<string, array{\Closure(LateReplyRedis): void}>
     */
    public static function errorRepliesAfterAFailure(): iterable
    {
        yield 'after the read timeout' => [static function (LateReplyRedis $redis): void {
            $redis->readTimeout = 0.05;
            $redis->stallMs = 60;
            $redis->stalls = 1;
            $redis->stallOn = 'HDEL';
            $redis->errorAfterStall = "READONLY You can't write against a read only replica.";
        }];

        yield 'on top of another exception' => [static function (LateReplyRedis $redis): void {
            $redis->errorAfterFailure = "READONLY You can't write against a read only replica.";
        }];
    }

    /**
     * phpredis releases after 6.3 close the socket themselves after a read
     * timeout. close() on a client that is not connected would connect again
     * first, with AUTH and SELECT, only to close.
     */
    public function testAClientThatClosedItsOwnSocketIsNotClosedAgain(): void
    {
        $redis = new LateReplyRedis();
        $pool = $this->pool($redis);
        $pool->getItem('warm');
        $item = $this->pool($redis)->getItem('k');

        $redis->closesOnStall = true;
        $redis->stalls = 1;

        self::assertFalse($pool->save($item->set('v')));
        self::assertSame(0, $redis->closedWhileClosed);
    }

    /**
     * A single guarded write whose connection is lost before the server read
     * it: sent again once, with a new epoch, on the new connection, and
     * stored. Symfony tries no item of a batch of one again itself.
     */
    public function testASingleGuardedWriteAcrossAQuickFailureIsSentAgainAndStored(): void
    {
        $redis = new LateReplyRedis();
        $pool = $this->pool($redis);
        $item = $pool->getItem('k');

        $redis->resets = 1;

        self::assertTrue($pool->save($item->set('v')));
        self::assertSame('v', $this->pool($redis)->getItem('k')->get());
    }

    /**
     * After a failure that waited out the read timeout, nothing of that batch
     * is sent again: a node that did not answer would keep it waiting as long.
     */
    public function testABatchThatWaitedOutTheReadTimeoutIsNotSentAgain(): void
    {
        $redis = new LateReplyRedis();
        $redis->readTimeout = 0.05;
        $pool = $this->pool($redis);
        $item = $pool->getItem('k');

        $redis->stalls = 1;
        $redis->stallMs = 60;
        $roundTrips = $redis->roundTrips;

        self::assertFalse($pool->save($item->set('v')));
        self::assertSame($roundTrips + 1, $redis->roundTrips);
    }

    /**
     * A pipeline that did not go out whole (phpredis answers false from
     * exec(), without throwing) was not answered: nothing is known of its
     * keys. The delete fails, nothing is unlinked, and the next delete writes
     * its tombstone.
     */
    public function testAPipelineThatWasNotAnsweredUnlinksNothing(): void
    {
        $redis = new LateReplyRedis();
        $pool = $this->pool($redis);
        $key = $this->namespace.':k';
        self::assertTrue($pool->save($this->pool($redis)->getItem('k')->set('v')));

        // the delete, and Symfony's retry of it
        $redis->execsAnswerFalse = 2;
        $roundTrips = $redis->roundTrips;

        self::assertFalse($pool->deleteItem('k'));
        self::assertSame([], $redis->unlinked, 'nothing unlinked');
        self::assertSame($roundTrips + 2, $redis->roundTrips, 'and nothing asked of the keys after them');

        self::assertTrue($pool->deleteItem('k'));
        self::assertSame(['e'], array_keys($redis->items[$key] ?? []), 'a tombstone');
        self::assertSame([], $redis->unlinked);
    }

    /**
     * A cluster answers false for any error reply, so both hash commands of a
     * tombstone answer false under READONLY, LOADING or NOPERM as well as for
     * a value of another type. Only the key's type tells them apart: a hash
     * is not unlinked, and the delete fails.
     */
    public function testATombstoneBothOfWhoseCommandsWereRefusedUnlinksOnlyAValueOfAnotherType(): void
    {
        $cluster = new FakeCluster();
        $pool = $this->pool($cluster);
        self::assertTrue($pool->save($this->pool($cluster)->getItem('k')->set('v')));

        $cluster->refusesHashWrites = true;

        self::assertFalse($pool->deleteItem('k'));
        self::assertFalse($pool->deleteItem('absent'));
        self::assertSame([], $cluster->unlinked);

        // the tombstone of a refused write: its read failed
        $cluster->refusesHashWrites = false;
        $cluster->failures = 1;
        $cluster->failureOn = 'hmget';
        $item = $pool->getItem('k');
        $cluster->refusesHashWrites = true;

        self::assertFalse($pool->save($item->set('w')));
        self::assertSame([], $cluster->unlinked);
    }

    /**
     * An unlink the server refuses (a cluster answers false for an error
     * reply) is a failed delete.
     */
    public function testAnUnlinkAnsweredFalseIsAFailedDelete(): void
    {
        $cluster = new FakeCluster();
        $pool = new VersionedRedisTagAwareAdapter($cluster, $this->namespace, rulesCache: false, guardWindowSeconds: 0);

        $cluster->unlinkAnswersFalse = true;

        self::assertFalse($pool->deleteItem('k'));
    }

    /**
     * A pipeline whose multi() throws (a connect that failed) sent nothing:
     * it is not sent again one call at a time, each of which would try to
     * connect again.
     */
    public function testAPipelineThatNeverWentOutIsNotSentAgain(): void
    {
        $redis = new LateReplyRedis();
        $pool = $this->pool($redis);
        $item = $pool->getItem('k');

        $redis->multiFailures = 1;
        [$multis, $roundTrips] = [$redis->multis, $redis->roundTrips];

        self::assertFalse($pool->save($item->set('v')));
        self::assertSame($multis + 1, $redis->multis, 'one attempt');
        self::assertSame($roundTrips, $redis->roundTrips, 'and nothing sent');
    }

    /**
     * Right after a command that waited out the read timeout, an
     * invalidation, a delete, a clear and a read are each sent: a refusal
     * would leave the old value to be served until its TTL.
     */
    public function testWhatFollowsACommandThatWaitedOutTheReadTimeoutIsSent(): void
    {
        $redis = new LateReplyRedis();
        $redis->readTimeout = 0.05;
        $pool = $this->pool($redis);
        self::assertTrue($pool->save($this->pool($redis)->getItem('k')->set('v')->tag('t')));

        $redis->stalls = 1;
        $redis->stallMs = 60;
        $redis->stallOn = 'HMGET';
        self::assertFalse($pool->getItem('j')->isHit());

        $roundTrips = $redis->roundTrips;
        self::assertTrue($pool->invalidateTags(['t']));
        self::assertSame($roundTrips + 1, $redis->roundTrips, 'the invalidation was sent');

        self::assertTrue($pool->deleteItem('k'));
        self::assertSame($roundTrips + 2, $redis->roundTrips, 'the delete was sent');

        self::assertTrue($pool->clear());
        self::assertSame($roundTrips + 3, $redis->roundTrips, 'the clear was sent');

        self::assertFalse($pool->getItem('k')->isHit());
        self::assertSame($roundTrips + 4, $redis->roundTrips, 'the read was sent');
    }

    /**
     * A command of this worker that timed out says nothing of the rules
     * stream: the next reload is sent, and the outage the workers of the
     * server share is not started by it.
     */
    public function testACommandThatWaitedOutTheReadTimeoutStartsNoSharedOutage(): void
    {
        $redis = new LateReplyRedis();
        $redis->readTimeout = 0.05;
        $shared = new ArrayAdapter();
        $pool = new VersionedRedisTagAwareAdapter($redis, $this->namespace, rulesCacheMs: 0, rulesCache: $shared);
        $pool->getItem('warm');

        $redis->stalls = 1;
        $redis->stallMs = 60;
        $redis->stallOn = 'HMGET';
        self::assertFalse($pool->getItem('j')->isHit());

        $pool->getItem('k');

        $rules = (new \ReflectionProperty($pool, 'sharedRules'))->getValue($pool);
        self::assertInstanceOf(SharedRules::class, $rules);
        self::assertFalse($rules->lastAttempt()[0], 'no outage');
    }

    /**
     * Symfony's lazy connection connects on its first command, and throws its
     * own exception when it cannot. Each command of the adapter asks it to
     * connect once.
     */
    public function testALazyConnectionThatCannotConnectIsAskedToConnectOncePerCommand(): void
    {
        $attempts = 0;
        $redis = RedisProxy::createLazyProxy(static function () use (&$attempts): \Redis {
            ++$attempts;

            throw new InvalidArgumentException('Redis connection failed: Connection refused.');
        });
        $pool = new VersionedRedisTagAwareAdapter($redis, $this->namespace, rulesCache: false);

        self::assertFalse($pool->getItem('k')->isHit());
        self::assertSame(1, $attempts, 'the read of the rules');

        self::assertFalse($pool->invalidateTags(['t']));
        self::assertSame(2, $attempts, 'the append of a rule');
    }

    /**
     * An EVALSHA that failed quickly is followed by the script's body; one
     * that waited out the read timeout is not.
     */
    public function testAnEvalshaThatWaitedOutTheReadTimeoutIsNotFollowedByAnEval(): void
    {
        self::skipWherePhpredisPipelinesClusters();

        $cluster = new FakeCluster();
        $cluster->readTimeout = 10.0;
        $pool = $this->pool($cluster);
        $cluster->failureOn = 'evalsha';

        $cluster->failures = 1;
        $evals = $cluster->evals;

        self::assertTrue($pool->save($pool->getItem('quick')->set('v')));
        self::assertSame($evals + 1, $cluster->evals, 'a quick failure: the body follows');

        $cluster->readTimeout = 0.05;
        $cluster->failureTakesMs = 60;
        $cluster->failures = 1;
        $item = $pool->getItem('slow');
        $evals = $cluster->evals;

        self::assertFalse($pool->save($item->set('v')));
        self::assertSame($evals, $cluster->evals, 'after the read timeout: no body');
    }

    /**
     * A typed cluster command that fails has its node's socket closed by
     * phpredis: the client is not closed, so the sockets of the replicas it
     * reads from stay open. A command sent to a given node, or a reply cut
     * short, may leave a late reply, and closes the client; phpredis 6.3
     * cannot read from replicas after that, which is logged once.
     */
    public function testAClusterIsClosedOnlyWhereALateReplyMayBeLeft(): void
    {
        $cluster = new FakeCluster(\RedisCluster::FAILOVER_DISTRIBUTE_SLAVES);
        $cluster->readTimeout = 0.05;
        $pool = $this->pool($cluster);
        $logger = new ConnectionWarnings();
        $pool->setLogger($logger);
        $pool->getItem('warm');
        $cluster->failureOn = 'hmget';

        // a typed command fails quickly, then after the read timeout
        foreach ([0, 60] as $takesMs) {
            $cluster->failureTakesMs = $takesMs;
            $cluster->failures = 1;
            self::assertFalse($pool->getItem('k')->isHit());
        }

        self::assertSame(0, $cluster->closes, 'not closed after a typed command');
        self::assertSame(0, $logger->replicas);

        // TIME, sent to the node that holds the rules
        $cluster->failureTakesMs = 0;
        $cluster->failureOn = 'time';
        $cluster->failures = 1;
        self::assertNull((new \ReflectionMethod($pool, 'serverNowMs'))->invoke($pool));
        self::assertSame(1, $cluster->closes, 'closed after a command sent to a node');

        // a reply cut short
        $cluster->failureOn = 'hmget';
        $cluster->failureCutsAReply = true;
        $cluster->failures = 1;
        self::assertFalse($pool->getItem('k')->isHit());
        self::assertSame(2, $cluster->closes, 'and after a reply cut short');

        self::assertSame(version_compare((string) phpversion('redis'), '6.4.0RC1', '<') ? 1 : 0, $logger->replicas, 'logged once where phpredis cannot read replicas again');
    }

    /**
     * A cluster pipeline that fails quickly is sent again one command at a
     * time. A redirect, or a refusal while queueing, is a whole reply and
     * closes nothing; a reply cut short closes the client first.
     */
    public function testAClusterPipelineThatFailsQuicklyIsSentAgainOneCommandAtATime(): void
    {
        $cluster = new PipelineCluster();
        $cluster->readTimeout = 10.0;
        $writer = $this->pool($cluster);
        $keys = ['k1', 'k2', 'k3'];

        foreach ($keys as $key) {
            self::assertTrue($writer->save($this->pool($cluster)->getItem($key)->set('v')));
        }

        foreach ([[false, 0], [true, 1]] as [$cutsAReply, $closes]) {
            $cluster->failureCutsAReply = $cutsAReply;
            $cluster->execFailures = 1;
            $direct = $cluster->direct;
            $hits = 0;

            foreach ($this->pool($cluster)->getItems($keys) as $item) {
                $hits += $item->isHit() ? 1 : 0;
            }

            self::assertSame(\count($keys), $hits);
            self::assertSame($direct + \count($keys), $cluster->direct, 'sent again one read at a time');
            self::assertSame($closes, $cluster->closes);
        }

        // a raw command refused while queued: nothing went out
        $send = new \ReflectionMethod($writer, 'pipeline');
        $send->invoke($writer, [['rawCommand', $this->namespace.':raw', 'HSETEX', $this->namespace.':raw', 'PX', '60000', 'FIELDS', '1', 'f', 'v']]);
        self::assertSame(1, $cluster->closes);
    }

    /**
     * A read timeout of 0 is not "no limit": phpredis waits PHP's
     * default_socket_timeout then. And a failure counts from a hair under the
     * limit, where phpredis gives up.
     */
    public function testAReadTimeoutOfZeroWaitsAsLongAsPhpsDefaultSocketTimeout(): void
    {
        $waitedOut = new \ReflectionMethod(VersionedRedisTagAwareAdapter::class, 'waitedOut');
        $redis = new LateReplyRedis();
        $default = \ini_get('default_socket_timeout');

        try {
            ini_set('default_socket_timeout', '2');

            self::assertTrue($waitedOut->invoke(null, $redis, 1.95e9), 'a timeout of 0 waits default_socket_timeout');
            self::assertFalse($waitedOut->invoke(null, $redis, 1.85e9));

            $redis->readTimeout = 1.5;

            self::assertTrue($waitedOut->invoke(null, $redis, 1.45e9), 'phpredis gives up a hair early');
            self::assertFalse($waitedOut->invoke(null, $redis, 1.40e9));

            ini_set('default_socket_timeout', '-1');
            $redis->readTimeout = 0.0;

            self::assertFalse($waitedOut->invoke(null, $redis, 1e12), 'a negative default_socket_timeout waits for ever');
        } finally {
            ini_set('default_socket_timeout', (string) $default);
        }
    }

    /**
     * A server out of memory refuses the tombstone's epoch and still runs its
     * HDEL, and a cluster answers false for that rather than throw. The delete
     * answers false, and the key keeps its epoch rather than be unlinked.
     */
    public function testATombstoneRefusedForWantOfMemoryUnlinksNothing(): void
    {
        $cluster = new FakeCluster();
        $pool = $this->pool($cluster);
        $key = $this->namespace.':k';

        self::assertTrue($pool->save($this->pool($cluster)->getItem('k')->set('v')));
        $epochOf = static fn (): ?string => $cluster->items[$key]['e'] ?? null;
        $epoch = $epochOf();
        self::assertIsString($epoch);

        $cluster->outOfMemory = true;

        self::assertFalse($pool->deleteItem('k'), 'the delete failed');
        self::assertSame([], $cluster->unlinked, 'nothing was unlinked');
        self::assertSame($epoch, $epochOf(), 'and the key kept its epoch');

        // the same for the tombstone of a refused write: its read failed
        $cluster->outOfMemory = false;
        $cluster->failures = 1;
        $cluster->failureOn = 'hmget';
        $item = $pool->getItem('k');
        $cluster->outOfMemory = true;

        self::assertFalse($pool->save($item->set('w')));
        self::assertSame([], $cluster->unlinked);
        self::assertSame($epoch, $epochOf());
    }

    /**
     * A value of another type refuses both hash commands of a tombstone: the
     * key is unlinked instead.
     */
    public function testAValueOfAnotherTypeIsUnlinkedInsteadOfTombstoned(): void
    {
        $cluster = new FakeCluster();
        $key = $this->namespace.':k';
        $cluster->strings[$key] = true;

        self::assertTrue($this->pool($cluster)->deleteItem('k'));
        self::assertSame([$key], $cluster->unlinked);
    }

    /**
     * A short-lived item's plain write is two HSETEX: the epoch, then the
     * data. A guarded write of another worker that lands between them finds
     * the new epoch and is refused; were the data first, it would replace the
     * newer value and keep it.
     */
    public function testAShortLivedPlainWriteSetsItsEpochBeforeItsData(): void
    {
        $cluster = new PipelineCluster();
        $key = $this->namespace.':k';
        $seed = $this->pool($cluster);
        self::assertTrue($seed->save($seed->getItem('k')->set('older')));
        self::assertTrue($seed->deleteItem('k'));

        // a computation reads the tombstone
        $reader = $this->pool($cluster);
        $read = $reader->getItem('k');
        self::assertFalse($read->isHit());

        // another worker writes a newer value, plainly: it read nothing
        $writer = $this->pool($cluster);
        $writer->getItem('warm');
        $item = $this->pool($cluster)->getItem('k');
        $refused = null;

        $cluster->afterTypedWrite = static function (string $written) use ($cluster, $key, $reader, $read, &$refused): void {
            if ($key !== $written) {
                return;
            }

            $cluster->afterTypedWrite = null;
            $refused = !$reader->save($read->set('computed from the tombstone')->expiresAfter(10));
        };

        self::assertTrue($writer->save($item->set('newer')->expiresAfter(10)));
        self::assertTrue($refused, 'the guarded write between the two commands was refused');
        self::assertSame('newer', $this->pool($cluster)->getItem('k')->get());
    }

    /**
     * Its epoch first, a plain write is stored only where its data was too.
     */
    public function testAPlainWriteWhoseDataWasRefusedIsReportedFailed(): void
    {
        $cluster = new FakeCluster();
        $item = $this->pool($cluster)->getItem('k');
        $pool = $this->pool($cluster);
        $pool->getItem('warm');

        $cluster->refusedField = 'v';

        self::assertFalse($pool->save($item->set('v')->expiresAfter(10)));
    }

    private static function skipWherePhpredisPipelinesClusters(): void
    {
        if (FakeCluster::pipelinesClusters()) {
            self::markTestSkipped(\sprintf('phpredis %s has cluster pipelines, so a cluster client without them cannot be stood in for.', phpversion('redis')));
        }
    }

    private function pool(\Redis|\RedisCluster $client): VersionedRedisTagAwareAdapter
    {
        return new VersionedRedisTagAwareAdapter($client, $this->namespace, rulesCache: false);
    }
}

/**
 * Counts the warnings that the replicas cannot be read after a close.
 */
final class ConnectionWarnings extends AbstractLogger
{
    public int $replicas = 0;

    public function log($level, string|\Stringable $message, array $context = []): void
    {
        if (LogLevel::WARNING === $level && str_contains((string) $message, 'reads sent to the replicas fail')) {
            ++$this->replicas;
        }
    }
}
