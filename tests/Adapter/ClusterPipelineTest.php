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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;

/**
 * A cluster pipelines across its slots only where phpredis has cluster
 * pipelines, which no release has yet. Until one does, a 100-key read is 100
 * round trips, and the cluster CI legs prove that path against real nodes.
 *
 * The capable path is pinned here twice: against a stand-in that has
 * pipeline() on any release (PipelineCluster), and against the cluster the
 * suite runs on, through a phpredis that has cluster pipelines (SpyCluster),
 * skipped elsewhere. phpredis refuses a raw command in a cluster pipeline and
 * aborts the whole pipeline on a redirect; either way the batch must still be
 * answered, and the client left ready for its next command.
 */
final class ClusterPipelineTest extends TestCase
{
    private const KEYS = 100;

    private string $namespace;

    private ?SpyCluster $real = null;

    protected function setUp(): void
    {
        $this->namespace = 'pipe'.bin2hex(random_bytes(3));
    }

    protected function tearDown(): void
    {
        if (null !== $this->real) {
            Connection::sweep($this->real, $this->namespace.'*');
        }
    }

    public function testACapableClusterGetsOnePipelinePerBatch(): void
    {
        $cluster = new PipelineCluster();
        self::fill($cluster);

        self::assertSame(self::KEYS, self::hits(new VersionedRedisTagAwareAdapter($cluster, 'pipe', rulesCache: false)));
        self::assertSame(1, $cluster->execs, 'the batch went out as one pipeline');
        self::assertSame(0, $cluster->direct, 'and nothing on its own');
    }

    public function testAClusterWithoutPipelinesSendsOneCommandAtATime(): void
    {
        // a subclass inherits pipeline() where the release has it, so no
        // stand-in can be a cluster without one there
        if (FakeCluster::pipelinesClusters()) {
            self::markTestSkipped(\sprintf('phpredis %s has cluster pipelines, so a cluster client without them cannot be stood in for.', phpversion('redis')));
        }

        $cluster = new FakeCluster();
        self::fill($cluster);

        self::assertSame(self::KEYS, self::hits(new VersionedRedisTagAwareAdapter($cluster, 'pipe', rulesCache: false)));
        self::assertSame(self::KEYS, $cluster->direct, 'one call per key');
        self::assertSame(0, $cluster->multis, 'no pipeline was asked of a client that has none');
    }

    /**
     * @param \Closure(): (PipelineCluster|SpyCluster) $open
     */
    #[DataProvider('clientsReadingPrimaries')]
    public function testARefusedRawCommandIsSentOnItsOwnAndLands(\Closure $open): void
    {
        $cluster = $this->opened($open);
        $pool = $this->pool($cluster);
        $key = $this->namespace.':raw';

        self::assertSame([1], self::send($pool, [self::rawWrite($key, 'landed')]));
        self::assertSame(1, $cluster->multis, 'it was queued in a pipeline first');
        self::assertSame(['f' => 'landed'], $cluster->hmget($key, ['f']));
    }

    /**
     * @param \Closure(): (PipelineCluster|SpyCluster) $open
     */
    #[DataProvider('clientsReadingPrimaries')]
    public function testARefusedRawCommandKeepsLaterBatchesWithOneOutOfAPipeline(\Closure $open): void
    {
        $cluster = $this->opened($open);
        $pool = $this->pool($cluster);
        $key = $this->namespace.':raw';

        self::send($pool, [self::rawWrite($key, 'first')]);
        $multis = $cluster->multis;

        self::assertSame([1], self::send($pool, [self::rawWrite($key, 'second')]));
        self::assertSame($multis, $cluster->multis, 'the second raw write did not enter a pipeline');
        self::assertSame(['f' => 'second'], $cluster->hmget($key, ['f']));

        // a batch without one still goes out as a pipeline
        self::assertSame([['f' => 'second']], self::send($pool, [['hMGet', $key, ['f']]]));
        self::assertSame($multis + 1, $cluster->multis);
    }

    /**
     * @param \Closure(): (PipelineCluster|SpyCluster) $open
     */
    #[DataProvider('clients')]
    public function testAPipelineThatFailsInExecIsReadAgainOneKeyAtATime(\Closure $open): void
    {
        $cluster = $this->opened($open);
        $keys = $this->write($cluster);
        $execs = $cluster->execs;
        $direct = $cluster->direct;

        // the pipeline runs, then exec() throws, as phpredis does when one of
        // its commands was redirected
        $cluster->execFailures = 1;

        self::assertSame(\count($keys), self::hits($this->pool($cluster), $keys), 'every item is a hit');
        self::assertSame($execs + 1, $cluster->execs, 'one pipeline, which failed');
        self::assertSame($direct + \count($keys), $cluster->direct, 'and the batch again, one read per key');
    }

    /**
     * @param \Closure(): (PipelineCluster|SpyCluster) $open
     */
    #[DataProvider('clientsReadingPrimaries')]
    public function testAFailedPipelineIsNeverLeftOpen(\Closure $open): void
    {
        $cluster = $this->opened($open);
        $pool = $this->pool($cluster);
        $key = $this->namespace.':raw';
        $read = ['hMGet', $key, ['f']];

        // refused while queueing, after a read was queued: phpredis leaves
        // that pipeline open
        self::assertSame([['f' => false], 1], self::send($pool, [$read, self::rawWrite($key, 'refused')]));
        self::assertSame(\Redis::ATOMIC, $cluster->getMode());
        self::assertSame(['f' => 'refused'], $cluster->hmget($key, ['f']), 'the next command is answered, not queued');

        // exec() throws, and phpredis closes the pipeline itself
        $cluster->execFailures = 1;

        self::assertSame([['f' => 'refused']], self::send($pool, [$read]));
        self::assertSame(\Redis::ATOMIC, $cluster->getMode());

        // exec() throws and leaves a pipeline open, as a client might
        $cluster->execFailures = 1;
        $cluster->failureLeavesPipelineOpen = true;

        self::assertSame([['f' => 'refused']], self::send($pool, [$read]));
        self::assertSame(\Redis::ATOMIC, $cluster->getMode());
        self::assertSame(['f' => 'refused'], $cluster->hmget($key, ['f']), 'the next command is answered, not queued');
    }

    /**
     * phpredis passes the integers of its typed methods through a C long,
     * which is 32 bits wide on Windows; there the writes stay raw and go one
     * at a time.
     *
     * @param \Closure(): (PipelineCluster|SpyCluster) $open
     */
    #[DataProvider('clients')]
    public function testTypedWritesRideThePipeline(\Closure $open): void
    {
        $cluster = $this->opened($open);
        $keys = [];

        for ($i = 0; $i < self::KEYS; ++$i) {
            $keys[] = 'k'.$i;
        }

        // the reading pool is not the writing one: every write is a plain one
        $writer = $this->pool($cluster);

        foreach ($this->pool($cluster)->getItems($keys) as $item) {
            $writer->saveDeferred($item->set('v'));
        }

        [$multis, $execs, $raw, $typed] = [$cluster->multis, $cluster->execs, $cluster->raw, $cluster->typed];

        self::assertTrue($writer->commit());

        if ('Windows' === \PHP_OS_FAMILY) {
            self::assertSame($multis + 1, $cluster->multis, 'refused in the one pipeline it was queued in');
            self::assertSame($execs, $cluster->execs);
            self::assertSame($raw + 2 * self::KEYS, $cluster->raw, 'then sent one command at a time');
            self::assertSame(self::KEYS, self::hits($this->pool($cluster), $keys));

            return;
        }

        self::assertSame($execs + 1, $cluster->execs, 'every write in one pipeline');
        self::assertSame($raw, $cluster->raw, 'none of them raw');
        self::assertSame($typed + self::KEYS, $cluster->typed);
        self::assertSame(self::KEYS, self::hits($this->pool($cluster), $keys));

        // the tombstones of a delete ride it too
        $execs = $cluster->execs;

        self::assertTrue($writer->deleteItems($keys));
        self::assertSame($execs + 1, $cluster->execs);
        self::assertSame($raw, $cluster->raw);
        self::assertSame(0, self::hits($this->pool($cluster), $keys));
    }

    /**
     * A plain write sent again after exec() threw must not put back the epoch
     * its first run set: a read of that run, then a delete, then the repeat,
     * and the write after the read would be stored across the delete.
     */
    public function testAPlainWriteSentAgainRefusesAWriteWhoseReadSawItsFirstRun(): void
    {
        // on Windows the writes are raw commands, which never enter a cluster
        // pipeline: none of them is sent twice
        if ('Windows' === \PHP_OS_FAMILY) {
            self::markTestSkipped('On Windows the writes do not enter a cluster pipeline.');
        }

        $cluster = new PipelineCluster();
        $reader = $this->pool($cluster);
        $other = $this->pool($cluster);
        $read = null;

        // read by another pool, so the write is a plain one
        $item = $this->pool($cluster)->getItem('k');

        // 1. the batch runs, and exec() throws after it
        $cluster->execFailures = 1;
        $cluster->whileFailing = static function () use ($reader, $other, &$read): void {
            // 2. a rule outdates the item, and a reader misses on it, seeing
            // the epoch of the first run
            self::assertTrue($other->invalidateTags(['t']));
            $read = $reader->getItem('k');
            self::assertFalse($read->isHit());

            // 3. a delete lands
            self::assertTrue($other->deleteItem('k'));
        };

        // 4. the batch is sent again
        self::assertTrue($this->pool($cluster)->save($item->set('first')->tag('t')));
        self::assertInstanceOf(CacheItem::class, $read);

        // 5. the reader's write, computed before the delete
        self::assertFalse($reader->save($read->set('computed before the delete')->tag('t')), 'refused');
        self::assertFalse($this->pool($cluster)->getItem('k')->isHit());
    }

    /**
     * The same for a tombstone: a read of the first run, then another delete,
     * then the repeat.
     */
    public function testATombstoneSentAgainRefusesAWriteWhoseReadSawItsFirstRun(): void
    {
        // on Windows the writes are raw commands, which never enter a cluster
        // pipeline: none of them is sent twice
        if ('Windows' === \PHP_OS_FAMILY) {
            self::markTestSkipped('On Windows the writes do not enter a cluster pipeline.');
        }

        $cluster = new PipelineCluster();
        $reader = $this->pool($cluster);
        $other = $this->pool($cluster);
        $read = null;
        self::assertTrue($other->save($other->getItem('k')->set('old')));

        $cluster->execFailures = 1;
        $cluster->whileFailing = static function () use ($reader, $other, &$read): void {
            // a reader misses on the tombstone of the first run, then another
            // delete lands
            $read = $reader->getItem('k');
            self::assertFalse($read->isHit());
            self::assertTrue($other->deleteItem('k'));
        };

        self::assertTrue($this->pool($cluster)->deleteItem('k'));
        self::assertInstanceOf(CacheItem::class, $read);

        self::assertFalse($reader->save($read->set('computed before the second delete')), 'refused');
        self::assertFalse($this->pool($cluster)->getItem('k')->isHit());
    }

    /**
     * And for a guarded write that landed the first time: a read of that run,
     * then the key gone (evicted), then the repeat, which a key without an
     * epoch takes.
     */
    public function testAGuardedWriteSentAgainRefusesAWriteWhoseReadSawItsFirstRun(): void
    {
        $cluster = new PipelineCluster();
        $writer = $this->pool($cluster);
        $reader = $this->pool($cluster);
        $other = $this->pool($cluster);
        $key = $this->namespace.':k';
        $read = null;

        // the server holds the script, so the first run stores the write
        $warm = $writer->getItem('warm');
        self::assertTrue($writer->save($warm->set('v')));
        $item = $writer->getItem('k');

        $cluster->execFailures = 1;
        $cluster->whileFailing = static function () use ($cluster, $reader, $other, $key, &$read): void {
            self::assertTrue($other->invalidateTags(['t']));
            $read = $reader->getItem('k');
            self::assertFalse($read->isHit());
            $cluster->unlink($key);
        };

        self::assertTrue($writer->save($item->set('first')->tag('t')));
        self::assertInstanceOf(CacheItem::class, $read);

        self::assertFalse($reader->save($read->set('computed before the key went')->tag('t')), 'refused');
        self::assertFalse($this->pool($cluster)->getItem('k')->isHit());
    }

    /**
     * The guarded write sent as a body after its sha1 was refused gets a new
     * epoch too: the key gone and the script forgotten after the first run,
     * the repeat's EVALSHA refused, and the EVAL would otherwise put back the
     * epoch of the first run, which a key without an epoch takes.
     */
    public function testAGuardedWriteSentAgainAsABodyRefusesAWriteWhoseReadSawItsFirstRun(): void
    {
        $cluster = new PipelineCluster();
        $writer = $this->pool($cluster);
        $reader = $this->pool($cluster);
        $other = $this->pool($cluster);
        $key = $this->namespace.':k';
        $read = null;

        $warm = $writer->getItem('warm');
        self::assertTrue($writer->save($warm->set('v')));
        $item = $writer->getItem('k');

        $cluster->execFailures = 1;
        $cluster->whileFailing = static function () use ($cluster, $reader, $other, $key, &$read): void {
            self::assertTrue($other->invalidateTags(['t']));
            $read = $reader->getItem('k');
            self::assertFalse($read->isHit());
            $cluster->unlink($key);
            $cluster->flushScripts();
        };

        self::assertTrue($writer->save($item->set('first')->tag('t')));
        self::assertInstanceOf(CacheItem::class, $read);

        self::assertFalse($reader->save($read->set('computed before the key went')->tag('t')), 'refused');
        self::assertFalse($this->pool($cluster)->getItem('k')->isHit());
    }

    /**
     * A batch sent again after a refusal while it was queued gets new epochs
     * too, so it does not depend on the client sending nothing before exec():
     * this stand-in runs each call as it is queued.
     */
    public function testABatchSentAgainAfterARefusalWhileQueuedRefusesAWriteWhoseReadSawItsFirstRun(): void
    {
        // on Windows the plain write is itself a raw command, refused first
        if ('Windows' === \PHP_OS_FAMILY) {
            self::markTestSkipped('On Windows the writes do not enter a cluster pipeline.');
        }

        $cluster = new PipelineCluster();
        $pool = $this->pool($cluster);
        $reader = $this->pool($cluster);
        $other = $this->pool($cluster);
        $read = null;

        // the adapter's own plain write of an item tagged t, then a raw
        // command that the pipeline refuses
        $value = (new DefaultMarshaller())->marshall(['k' => ['value' => 'first']], $failed)['k'] ?? '';
        self::assertIsString($value);
        /** @var list<array{0: string, ...}> $write the calls as plainWrite() makes them */
        $write = (new \ReflectionMethod($pool, 'plainWrite'))->invoke($pool, $this->namespace.':k', $value, '["t"]', '0-0', 3_600_000);

        $cluster->whileRefusing = static function () use ($reader, $other, &$read): void {
            self::assertTrue($other->invalidateTags(['t']));
            $read = $reader->getItem('k');
            self::assertFalse($read->isHit());
            self::assertTrue($other->deleteItem('k'));
        };

        self::send($pool, [...$write, self::rawWrite($this->namespace.':other', 'v')]);
        self::assertInstanceOf(CacheItem::class, $read);

        self::assertFalse($reader->save($read->set('computed before the delete')->tag('t')), 'refused');
        self::assertFalse($this->pool($cluster)->getItem('k')->isHit());
    }

    /**
     * A refused save writes a tombstone; where the cluster did not answer
     * that, nothing is known of the key, and an unlink would wait for the
     * same node.
     */
    public function testARefusedSaveWhoseTombstoneWasNotAnsweredUnlinksNothing(): void
    {
        // on Windows the tombstone is raw commands, which never enter a
        // cluster pipeline
        if ('Windows' === \PHP_OS_FAMILY) {
            self::markTestSkipped('On Windows the writes do not enter a cluster pipeline.');
        }

        $cluster = new PipelineCluster();
        $pool = $this->pool($cluster);
        $cluster->readTimeout = 0.05;
        $cluster->failureTakesMs = 60;

        // a read the cluster did not answer: the epoch is unknown
        $cluster->execFailures = 1;
        $item = $pool->getItem('k');
        self::assertFalse($item->isHit());

        // the save after it is refused, and its tombstone not answered either
        $cluster->execFailures = 1;
        $execs = $cluster->execs;

        self::assertFalse($pool->save($item->set('v')));
        self::assertSame($execs + 1, $cluster->execs, 'the tombstone was sent');
        self::assertSame([], $cluster->unlinked);
    }

    /**
     * A node that did not answer within the read timeout would keep every
     * single command waiting as long: such a batch is not sent again.
     */
    public function testAPipelineThatFailedAfterTheReadTimeoutIsNotSentAgain(): void
    {
        $cluster = new PipelineCluster();
        $keys = $this->write($cluster);

        $cluster->readTimeout = 0.05;
        $cluster->failureTakesMs = 60;
        $cluster->execFailures = 1;
        $direct = $cluster->direct;

        self::assertSame(0, self::hits($this->pool($cluster), $keys), 'every call failed');
        self::assertSame($direct, $cluster->direct, 'and none was sent again');

        // a batch of guarded writes: no script sent again as a body
        $pool = $this->pool($cluster);
        $items = iterator_to_array($pool->getItems(['w1', 'w2']));
        $cluster->execFailures = 1;
        $execs = $cluster->execs;

        foreach ($items as $item) {
            $pool->saveDeferred($item->set('v'));
        }

        self::assertFalse($pool->commit());
        self::assertSame($execs + 1, $cluster->execs, 'one pipeline, not answered');

        // a delete: no unlink, which would wait for the same node. Symfony
        // may try a failed delete again key by key, so those fail too. On
        // Windows its raw commands never enter a pipeline
        if ('Windows' !== \PHP_OS_FAMILY) {
            $cluster->execFailures = 3;
            $cluster->unlinked = [];

            self::assertFalse($this->pool($cluster)->deleteItems(['w1', 'w2']));
            self::assertSame([], $cluster->unlinked);

            $cluster->execFailures = 0;
        }

        // a prune whose batch was not answered judged nothing
        $cluster->execFailures = 1;

        self::assertFalse($this->pool($cluster)->prune());
        self::assertTrue($this->pool($cluster)->prune(), 'and judges it the next time');

        // faster than the timeout, or with no timeout known: sent again
        foreach ([[0.05, 0], [0.0, 60]] as [$timeout, $takes]) {
            $cluster->readTimeout = $timeout;
            $cluster->failureTakesMs = $takes;
            $cluster->execFailures = 1;
            $direct = $cluster->direct;

            self::assertSame(self::KEYS, self::hits($this->pool($cluster), $keys), \sprintf('timeout %.2f s, failed after %d ms', $timeout, $takes));
            self::assertSame($direct + self::KEYS, $cluster->direct);
        }
    }

    /**
     * phpredis follows an ASK for a typed command and not for a raw one, so
     * a phpredis cluster is written through typed calls, with or without
     * pipelines; on Windows the writes stay raw (see above).
     */
    public function testAClusterIsWrittenThroughTypedCalls(): void
    {
        $cluster = new FakeCluster();
        $item = $this->pool($cluster)->getItem('k');

        self::assertTrue($this->pool($cluster)->save($item->set('v')));

        if ('Windows' === \PHP_OS_FAMILY) {
            self::assertSame(2, $cluster->raw, 'HSETEX and PEXPIRE, raw');

            return;
        }

        self::assertSame(0, $cluster->raw);
        self::assertArrayHasKey('v', $cluster->items[$this->namespace.':k'] ?? [], 'stored by a typed HSETEX');
    }

    /**
     * @return iterable<string, array{\Closure(): (PipelineCluster|SpyCluster)}>
     */
    public static function clients(): iterable
    {
        yield 'stand-in' => [static fn (): PipelineCluster => new PipelineCluster()];
        yield 'phpredis' => [SpyCluster::open(...)];
    }

    /**
     * The clients the adapter sends raw writes to: reading from the primaries.
     *
     * @return iterable<string, array{\Closure(): (PipelineCluster|SpyCluster)}>
     */
    public static function clientsReadingPrimaries(): iterable
    {
        yield 'stand-in' => [static fn (): PipelineCluster => new PipelineCluster()];
        yield 'phpredis' => [SpyCluster::openPrimaries(...)];
    }

    /**
     * @param \Closure(): (PipelineCluster|SpyCluster) $open
     */
    private function opened(\Closure $open): PipelineCluster|SpyCluster
    {
        $cluster = $open();

        if ($cluster instanceof SpyCluster) {
            $this->real = $cluster;
        }

        return $cluster;
    }

    private function pool(FakeCluster|PipelineCluster|SpyCluster $cluster): VersionedRedisTagAwareAdapter
    {
        return new VersionedRedisTagAwareAdapter($cluster, $this->namespace, rulesCache: false);
    }

    /**
     * Writes KEYS items through a pool, and answers their keys.
     *
     * @return list<string>
     */
    private function write(PipelineCluster|SpyCluster $cluster): array
    {
        $keys = [];

        for ($i = 0; $i < self::KEYS; ++$i) {
            $keys[] = 'k'.$i;
        }

        $pool = $this->pool($cluster);

        foreach ($pool->getItems($keys) as $item) {
            $pool->saveDeferred($item->set('v'));
        }

        self::assertTrue($pool->commit());

        return $keys;
    }

    /**
     * A write as the adapter sends it raw: HSETEX by key.
     *
     * @return array{0: string, ...}
     */
    private static function rawWrite(string $key, string $value): array
    {
        return ['rawCommand', $key, 'HSETEX', $key, 'PX', '60000', 'FIELDS', '1', 'f', $value];
    }

    /**
     * Sends calls the way the adapter sends a batch of its own.
     *
     * @param list<array{0: string, ...}> $calls
     *
     * @return list<mixed>
     */
    private static function send(VersionedRedisTagAwareAdapter $pool, array $calls): array
    {
        $answers = (new \ReflectionMethod($pool, 'pipeline'))->invoke($pool, $calls);
        self::assertIsArray($answers);

        return array_values($answers);
    }

    private static function fill(FakeCluster|PipelineCluster $cluster): void
    {
        for ($i = 0; $i < self::KEYS; ++$i) {
            $cluster->storeItem('pipe:k'.$i, 'v', ['t'], '0-0');
        }
    }

    /**
     * @param list<string>|null $keys the KEYS keys fill() wrote where null
     */
    private static function hits(VersionedRedisTagAwareAdapter $pool, ?array $keys = null): int
    {
        if (null === $keys) {
            $keys = [];

            for ($i = 0; $i < self::KEYS; ++$i) {
                $keys[] = 'k'.$i;
            }
        }

        $hits = 0;

        foreach ($pool->getItems($keys) as $item) {
            $hits += $item->isHit() ? 1 : 0;
        }

        return $hits;
    }
}
