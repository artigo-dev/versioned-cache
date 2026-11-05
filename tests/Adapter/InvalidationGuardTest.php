<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests\Adapter;

use Artigo\Cache\Adapter\MissRecords;
use Artigo\Cache\Adapter\VersionedRedisTagAwareAdapter;
use Artigo\Cache\Tests\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The write that follows a read: it must never store, as fresh, a value
 * computed from data that was deleted or invalidated while it was computed.
 *
 * Two adapters over two connections stand for two processes: A reads and
 * computes, B deletes or invalidates meanwhile. A third connection looks at
 * what the server holds. Nothing here assumes a single node, so the replica
 * leg runs it with reads answered by replicas.
 */
final class InvalidationGuardTest extends TestCase
{
    private \Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $a;
    private \Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $b;
    private \Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $raw;

    protected function setUp(): void
    {
        $this->a = Connection::open();
        $this->b = Connection::open();
        // what the server holds, read from the primaries
        $this->raw = Connection::openPrimaries();
        Connection::sweep($this->raw, 'guard*');
    }

    protected function tearDown(): void
    {
        if (isset($this->raw)) {
            Connection::sweep($this->raw, 'guard*');
        }
    }

    public function testAWriteAfterAnotherDeleteIsRefused(): void
    {
        $a = $this->pool($this->a);
        $item = $a->getItem('k');
        self::assertFalse($item->isHit());

        $this->pool($this->b)->deleteItem('k');

        self::assertFalse($a->save($item->set('computed before the delete')), 'refused');

        // read back from the primaries: a replica may not have the write yet
        $c = $this->pool($this->raw);
        $fresh = $c->getItem('k');
        self::assertFalse($fresh->isHit());
        self::assertTrue($c->save($fresh->set('computed after it')), 'a read after the delete writes');
        self::assertSame('computed after it', $c->getItem('k')->get());
    }

    public function testAWriteThroughIsWritten(): void
    {
        $a = $this->pool($this->a);
        $b = $this->pool($this->b);

        // an item A never read: a write-through, which nothing guards
        $item = $this->pool(Connection::open())->getItem('k');
        $b->deleteItem('k');
        self::assertTrue($a->save($item->set('written through')));

        // a hit forgets the miss before it: what follows is a write-through too
        $missed = $a->getItem('later');
        $writer = $this->pool($this->b);
        self::assertTrue($writer->save($writer->getItem('later')->set('written by B')));
        self::assertTrue($this->hitOnceReplicated($a, 'later'));
        $b->deleteItem('later');
        self::assertTrue($a->save($missed->set('written again')), 'the hit left nothing to compare');
        self::assertSame('written again', $this->pool($this->raw)->getItem('later')->get());
    }

    public function testAnOwnDeleteDoesNotRefuseTheOwnWrite(): void
    {
        $a = $this->pool($this->a);
        $item = $a->getItem('k');
        $a->deleteItem('k');

        self::assertTrue($a->save($item->set('v')), 'the pool deleted it itself, and writes after');
        self::assertTrue($this->pool($this->raw)->getItem('k')->isHit());
    }

    public function testAnOwnTagInvalidationKeepsTheOtherMissesGuarded(): void
    {
        $a = $this->pool($this->a);
        $item = $a->getItem('k');
        self::assertTrue($a->invalidateTags(['other']));

        $this->pool($this->b)->deleteItem('k');

        self::assertFalse($a->save($item->set('v')), 'its own rule did not let a delete from elsewhere through');
    }

    public function testAForeignRuleBeforeTheOwnOneStillInvalidates(): void
    {
        $a = $this->pool($this->a);
        $item = $a->getItem('k');

        // a rule from elsewhere, then this pool's own: the read saw neither
        self::assertTrue($this->pool($this->b)->invalidateTags(['t']));
        self::assertTrue($a->invalidateTags(['other']));

        self::assertTrue($a->save($item->set('computed before both')->tag('t')));
        self::assertFalse($a->getItem('k')->isHit(), 'the own rule does not vouch for the one before it');
    }

    public function testAShortLivedWriteKeepsTheMark(): void
    {
        $a = $this->pool($this->a);
        $item = $a->getItem('k');

        // B writes it with a life shorter than the window, and it expires
        $written = $this->pool($this->b)->getItem('k');
        self::assertTrue($this->pool($this->b)->save($written->set('short')->expiresAfter(1)));
        $this->waitPast(1.0);

        self::assertSame(['e'], array_keys((array) $this->raw->hGetAll('guard:k')), 'the data is gone, the epoch is not');
        self::assertFalse($a->save($item->set('computed before B wrote')), 'refused');
    }

    public function testAWriteThroughRefusesAnOlderRecomputation(): void
    {
        $a = $this->pool($this->a);
        $item = $a->getItem('k');

        // B writes it through, without a read of its own
        $through = $this->pool(Connection::open())->getItem('k');
        self::assertTrue($this->pool($this->b)->save($through->set('newer')));

        self::assertFalse($a->save($item->set('older')), 'the write-through gave the item a new epoch');
        self::assertSame('newer', $this->pool($this->raw)->getItem('k')->get());
    }

    public function testAValueThatDoesNotUnmarshalIsAGuardedMiss(): void
    {
        // an object of a class that does not exist: the marshaller refuses it
        // without a PHP warning, where igbinary warns about plain junk
        $this->raw->hMSet('guard:k', ['v' => 'O:30:"Artigo\Cache\Tests\NoSuchClass":0:{}', 't' => '[]', 'm' => '0-0', 'e' => 'e0']);

        $a = $this->pool($this->a);
        $item = $a->getItem('k');
        self::assertFalse($item->isHit());

        $this->pool($this->b)->deleteItem('k');

        self::assertFalse($a->save($item->set('v')), 'a miss like any other, and guarded like one');
    }

    public function testTheGuardSwitchesOff(): void
    {
        $a = $this->pool($this->a, 0);
        $item = $a->getItem('k');
        $this->pool($this->b, 0)->deleteItem('k');

        self::assertSame(0, $this->exists('guard:k'), 'a delete unlinks');
        self::assertTrue($a->save($item->set('v')), 'and a raced write lands, as before the guard');
        self::assertFalse($this->raw->hExists('guard:k', 'e'), 'no epoch is written');
    }

    public function testATombstoneIsAMissForTheWindowOnly(): void
    {
        // an item whose key lives shorter than the window, as an older writer
        // left it: the tombstone must still last the window
        $this->hSetEx('guard:k', ['v' => 'x', 't' => '[]', 'm' => '0-0'], 400);
        $this->raw->pExpire('guard:k', 400);

        $a = $this->pool($this->a, 1);
        $a->deleteItem('k');

        self::assertSame(['e'], array_keys((array) $this->raw->hGetAll('guard:k')));
        self::assertLessThanOrEqual(1_000, $this->raw->pttl('guard:k'));
        self::assertFalse($a->getItem('k')->isHit());

        $this->waitPast(0.6);
        self::assertSame(1, $this->exists('guard:k'), 'past the item\'s own TTL, the tombstone is still there');

        $this->waitPast(1.2);
        self::assertSame(0, $this->exists('guard:k'), 'past the window, nothing is');

        self::assertTrue($a->save($a->getItem('k')->set('v')->expiresAfter(60)));
        self::assertGreaterThan(1_000, $this->raw->pttl('guard:k'), 'a later write has a TTL of its own');
    }

    public function testARawValueIsRemovedNotTombstoned(): void
    {
        $this->raw->set('guard:counter', '5');

        self::assertTrue($this->pool($this->a)->deleteItem('counter'));
        self::assertSame(1, $this->raw->incr('guard:counter'), 'the string was unlinked, not left beside a tombstone');
    }

    public function testAWriteAfterATagInvalidationIsNeverServed(): void
    {
        $a = $this->pool($this->a);
        $item = $a->getItem('k');

        self::assertTrue($this->pool($this->b)->invalidateTags(['t']));

        self::assertTrue($a->save($item->set('computed before the rule')->tag('t')), 'it lands, stamped where its read was');
        self::assertFalse($a->getItem('k')->isHit(), 'and the rule judges it');
    }

    public function testATagInvalidationWhileAComputationRunsIsNeverServed(): void
    {
        $a = $this->pool($this->a);
        $b = $this->pool($this->b);

        $value = $a->get('k', static function (ItemInterface $item) use ($b): string {
            $item->tag('t');
            $b->invalidateTags(['t']);

            return 'computed across the rule';
        }, metadata: $metadata);

        self::assertSame('computed across the rule', $value, 'the caller still gets its value');
        self::assertFalse($a->getItem('k')->isHit());

        if (\defined(ItemInterface::class.'::METADATA_SAVE_FAILED')) {
            self::assertIsArray($metadata);
            self::assertArrayNotHasKey(ItemInterface::METADATA_SAVE_FAILED, $metadata, 'it was not refused: it landed, and reads stale');
        }
    }

    public function testAColdTagIsCoveredOnAFreshAdapter(): void
    {
        // a warm tag: one the stream already has a rule for
        self::assertTrue($this->pool($this->b)->invalidateTags(['warm']));

        $rows = [
            'warm' => static fn (VersionedRedisTagAwareAdapter $b) => $b->invalidateTags(['warm']),
            'cold' => static fn (VersionedRedisTagAwareAdapter $b) => $b->invalidateTags(['cold']),
            'delete' => static fn (VersionedRedisTagAwareAdapter $b) => $b->deleteItem('delete'),
        ];

        foreach ($rows as $row => $race) {
            // a fresh adapter, as a request under PHP-FPM is
            $a = $this->pool(Connection::open());
            $b = $this->pool($this->b);

            $a->get($row, static function (ItemInterface $item) use ($b, $race, $row): string {
                $item->tag($row);
                $race($b);

                return 'computed across the race';
            });

            self::assertFalse($this->pool(Connection::open())->getItem($row)->isHit(), \sprintf('the %s row is never served', $row));
        }
    }

    public function testAClearWhileAComputationRunsIsNeverServed(): void
    {
        $b = $this->pool($this->b);
        $a = $this->pool($this->a);
        self::assertTrue($a->save($a->getItem('written')->set('before')));

        foreach (['written', 'never-written'] as $key) {
            $a->get($key, static function () use ($b): string {
                $b->clear();

                return 'computed across the clear';
            }, \INF);

            self::assertFalse($a->getItem($key)->isHit(), \sprintf('"%s" is not served', $key));
        }
    }

    public function testAMissOlderThanTheWindowStillSeesTheRulesItMissed(): void
    {
        $a = $this->pool($this->a, 1);
        $item = $a->getItem('k');

        $b = $this->pool($this->b, 1);
        $b->deleteItem('k');
        self::assertTrue($b->invalidateTags(['t']));

        // the computation outlives the window: the tombstone is gone
        $this->waitPast(2.0);

        self::assertTrue($a->save($item->set('computed long ago')->tag('t')), 'nothing left to refuse it');
        self::assertFalse($a->getItem('k')->isHit(), 'but it is stamped where its read was, and the rule judges it');
    }

    public function testTheBatchPathIsGuarded(): void
    {
        $a = $this->pool($this->a);
        $b = $this->pool($this->b);
        $keys = [];

        for ($i = 0; $i < 100; ++$i) {
            $keys[] = 'batch'.$i;
        }

        // a batch loader: one read, the misses computed, saved deferred
        $items = iterator_to_array($a->getItems($keys));

        // meanwhile, elsewhere: one tag falls, one key is deleted
        self::assertTrue($b->invalidateTags(['tag-batch0']));
        self::assertTrue($b->deleteItem('batch1'));

        foreach ($items as $key => $item) {
            $a->saveDeferred($item->set('computed '.$key)->tag('tag-'.$key));
        }

        self::assertFalse($a->commit(), 'the refused write fails the commit');

        $fresh = 0;

        foreach ($this->pool($this->raw)->getItems($keys) as $item) {
            $fresh += $item->isHit() ? 1 : 0;
        }

        self::assertSame(98, $fresh);
        self::assertFalse($a->getItem('batch0')->isHit(), 'stale: stamped before its rule');
        self::assertFalse($a->getItem('batch1')->isHit(), 'refused: deleted while computed');
    }

    public function testABatchLargerThanTheFloorIsGuardedWhole(): void
    {
        $a = $this->pool($this->a);
        $count = MissRecords::CAP + 500;
        $items = iterator_to_array($a->getItems(array_map(static fn (int $i): string => 'big'.$i, range(0, $count - 1))));

        // the first read of the batch: the one a fixed cap forgets first
        self::assertTrue($this->pool($this->b)->deleteItem('big0'));

        foreach ($items as $item) {
            $a->saveDeferred($item->set('v'));
        }

        self::assertFalse($a->commit(), 'one write was refused');
        self::assertFalse($a->getItem('big0')->isHit(), 'the first read of the batch was still remembered');
        self::assertTrue($this->pool($this->raw)->getItem('big'.($count - 1))->isHit());
    }

    public function testBatchCacheSavesAreGuarded(): void
    {
        if (!class_exists('Symfony\Component\Cache\BatchCache')) {
            self::markTestSkipped('Symfony\'s BatchCache (symfony/symfony#67371) is not in this release.');
        }

        self::fail('BatchCache arrived: write the test against its save path.');
    }

    public function testANestedComputationIsGuardedForEachKey(): void
    {
        $a = $this->pool($this->a);
        $b = $this->pool($this->b);
        self::assertTrue($a->save($a->getItem('inner')->set('before')->tag('inner')));

        $a->get('outer', static function (ItemInterface $item) use ($a, $b): string {
            $item->tag('outer');

            // a forced recomputation of a hit, inside the outer one
            $a->get('inner', static function (ItemInterface $item) use ($b): string {
                $item->tag('inner');
                $b->invalidateTags(['outer']);
                $b->deleteItem('inner');

                return 'inner, computed across a delete';
            }, \INF);

            return 'outer, computed across its rule';
        });

        self::assertFalse($a->getItem('outer')->isHit(), 'the outer computation reads stale');
        self::assertFalse($a->getItem('inner')->isHit(), 'the inner one was refused');
    }

    public function testAForcedRecomputationAcrossADeleteIsRefused(): void
    {
        $a = $this->pool($this->a);
        $b = $this->pool($this->b);
        self::assertTrue($a->save($a->getItem('k')->set('before')));

        $a->get('k', static function () use ($b): string {
            $b->deleteItem('k');

            return 'recomputed across a delete';
        }, \INF);

        self::assertFalse($a->getItem('k')->isHit(), 'the forced write was refused');
    }

    public function testARefusedWriteIsNotRetriedPlain(): void
    {
        $a = $this->pool($this->a);
        $items = iterator_to_array($a->getItems(['x', 'y']));
        $this->pool($this->b)->deleteItem('x');

        foreach ($items as $item) {
            $a->saveDeferred($item->set('v'));
        }

        self::assertFalse($a->commit(), 'x was refused');
        self::assertTrue($this->pool($this->raw)->getItem('y')->isHit(), 'y landed');

        // whatever saves it again from the same read is refused again
        $a->saveDeferred($items['x']->set('again'));

        self::assertFalse($a->commit());
        self::assertFalse($a->getItem('x')->isHit());
    }

    public function testAnotherConnectionSeesAGuardedWriteAtOnce(): void
    {
        // another connection read the miss first: Relay keeps what it read
        self::assertFalse($this->pool(Connection::open())->getItem('k')->isHit());

        $a = $this->pool($this->a);
        self::assertTrue($a->save($a->getItem('k')->set('v')), 'a guarded write, after the read of its own pool');

        self::assertTrue($this->pool(Connection::open())->getItem('k')->isHit(), 'no copy of the miss is served after the script wrote the key');
    }

    public function testAnInvalidationAfterTheOwnRuleStillInvalidates(): void
    {
        $a = $this->pool($this->a);
        $item = $a->getItem('k');

        self::assertTrue($a->invalidateTags(['own']));
        self::assertTrue($this->pool($this->b)->invalidateTags(['t']));

        self::assertTrue($a->save($item->set('v')->tag('t')));
        self::assertFalse($a->getItem('k')->isHit(), 'the rule after the own one still judges it');
    }

    private function pool(\Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis, int $window = 60): VersionedRedisTagAwareAdapter
    {
        return new VersionedRedisTagAwareAdapter($redis, 'guard', rulesCacheMs: 0, rulesCache: false, guardWindowSeconds: $window);
    }

    private function exists(string $key): int
    {
        $exists = $this->raw->exists($key);

        return \is_int($exists) ? $exists : (int) (bool) $exists;
    }

    /**
     * Reads $key through $pool until it is a hit, for a second at most: a
     * replica holds a write a moment after its primary does.
     */
    private function hitOnceReplicated(VersionedRedisTagAwareAdapter $pool, string $key): bool
    {
        $deadline = microtime(true) + 1;

        while (!$hit = $pool->getItem($key)->isHit()) {
            if (microtime(true) > $deadline) {
                break;
            }

            usleep(10_000);
        }

        return $hit;
    }

    /**
     * Sleeps past a deadline as the server's clock counts it, which a
     * container's may count more slowly than ours.
     */
    private function waitPast(float $seconds): void
    {
        $deadline = $this->serverTime() + $seconds;

        do {
            usleep(50_000);
        } while ($this->serverTime() < $deadline);
    }

    private function serverTime(): float
    {
        $time = $this->clustered() ? $this->raw->time('guard:k') : $this->raw->time();
        $time = \is_array($time) ? array_values($time) : [];

        return (is_numeric($time[0] ?? null) ? (int) $time[0] : 0) + (is_numeric($time[1] ?? null) ? (int) $time[1] : 0) / 1e6;
    }

    /**
     * A typed call where the client has one: a raw command on a cluster that
     * reads from replicas goes to the replica its slot was last read from.
     *
     * @param array<string, string> $fields
     */
    private function hSetEx(string $key, array $fields, int $ttlMs): void
    {
        if (method_exists($this->raw, 'hsetex')) { // @phpstan-ignore function.alreadyNarrowedType (the stubs describe phpredis 6.3; an older one has no hsetex())
            $this->raw->hsetex($key, $fields, ['PX' => $ttlMs]);

            return;
        }

        $arguments = [$key, 'PX', (string) $ttlMs, 'FIELDS', (string) \count($fields)];

        foreach ($fields as $field => $value) {
            $arguments[] = $field;
            $arguments[] = $value;
        }

        $this->clustered() ? $this->raw->rawCommand($key, 'HSETEX', ...$arguments) : $this->raw->rawCommand('HSETEX', ...$arguments);
    }

    private function clustered(): bool
    {
        return $this->raw instanceof \RedisCluster || $this->raw instanceof \Relay\Cluster;
    }
}
