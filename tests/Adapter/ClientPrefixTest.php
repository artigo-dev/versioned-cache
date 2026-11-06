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
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * A connection with a client-side key prefix (OPT_PREFIX). The client puts it
 * on the keys of its typed calls and of a script's KEYS, and sends a raw
 * command as it is: every key the pool writes, deletes or drops must still
 * live under the prefix, and nothing outside it.
 *
 * The pools write through one connection, which on the replica leg reads
 * from replicas, and are read back through another that asks the primaries.
 * A third, without the prefix, looks at what the server holds.
 *
 * Each test runs in a process of its own: once a Relay process has used a
 * server under a key prefix, its other connections may keep serving a miss
 * they cached across another connection's script write, which the suite's
 * later Relay tests would then see.
 */
#[RunTestsInSeparateProcesses]
final class ClientPrefixTest extends TestCase
{
    private const NAMESPACE = 'clientprefix';

    private string $prefix;

    private \Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis;
    private \Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $other;
    private \Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $reads;
    private \Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $raw;

    protected function setUp(): void
    {
        $this->prefix = 'pfx'.bin2hex(random_bytes(3)).':';
        $this->redis = Connection::open(prefix: $this->prefix);
        $this->other = Connection::open(prefix: $this->prefix);
        $this->reads = Connection::openPrimaries($this->prefix);
        $this->raw = Connection::openPrimaries();
    }

    protected function tearDown(): void
    {
        if (isset($this->raw)) {
            Connection::sweep($this->raw, $this->prefix.'*');
            // what a raw command would have left without the prefix
            Connection::sweep($this->raw, self::NAMESPACE.'*');
        }
    }

    public function testAPlainWriteLandsUnderThePrefix(): void
    {
        foreach ([60, 0] as $window) {
            $key = 'plain'.$window;

            // an item this pool never read: the write is a plain one
            $item = $this->pool($this->other, $window)->getItem($key);
            self::assertTrue($this->pool($this->redis, $window)->save($item->set('written')));

            self::assertSame('written', $this->pool($this->reads, $window)->getItem($key)->get(), \sprintf('read back, guard window %d', $window));
            self::assertSame(1, $this->exists($this->prefix.self::NAMESPACE.':'.$key));
            self::assertSame(0, $this->exists(self::NAMESPACE.':'.$key), 'nothing outside the prefix');
        }
    }

    public function testAGuardedWriteLandsUnderThePrefix(): void
    {
        $pool = $this->pool($this->redis);

        self::assertSame('computed', $pool->get('guarded', static fn (): string => 'computed'));
        self::assertSame('computed', $this->pool($this->reads)->getItem('guarded')->get());
        self::assertSame(0, $this->exists(self::NAMESPACE.':guarded'));
    }

    public function testADeleteReachesTheValue(): void
    {
        foreach ([60, 0] as $window) {
            $key = 'deleted'.$window;
            $this->write($key, 'before', 't');

            self::assertTrue($this->pool($this->other, $window)->deleteItem($key));

            self::assertFalse($this->pool($this->reads, $window)->getItem($key)->isHit(), \sprintf('the delete stands, guard window %d', $window));
            self::assertSame(0 === $window ? [] : ['e'], $this->fields($this->prefix.self::NAMESPACE.':'.$key), 'a tombstone, or nothing');
            self::assertSame(0, $this->exists(self::NAMESPACE.':'.$key), 'nothing outside the prefix');
        }
    }

    public function testAWriteAcrossADeleteIsRefused(): void
    {
        $pool = $this->pool($this->redis);
        $other = $this->pool($this->other);

        $value = $pool->get('raced', static function () use ($other): string {
            $other->deleteItem('raced');

            return 'computed across a delete';
        });

        self::assertSame('computed across a delete', $value);
        self::assertFalse($this->pool($this->reads)->getItem('raced')->isHit(), 'the tombstone was where the write compared');
    }

    public function testAnInvalidationReachesTheValueAndTheReadDropsIt(): void
    {
        $this->write('k', 'before', 't');

        self::assertTrue($this->pool($this->other)->invalidateTags(['t']));
        self::assertSame(1, $this->exists($this->prefix.self::NAMESPACE.':@rules'), 'the stream is under the prefix');

        self::assertFalse($this->pool($this->reads)->getItem('k')->isHit());
        self::assertSame(['e'], $this->fields($this->prefix.self::NAMESPACE.':k'), 'the read dropped the data and kept the epoch');
        self::assertSame(0, $this->exists(self::NAMESPACE.':k'), 'nothing outside the prefix');
    }

    public function testAClearReachesTheValue(): void
    {
        $this->write('k', 'before', 't');

        self::assertTrue($this->pool($this->other)->clear());

        self::assertFalse($this->pool($this->reads)->getItem('k')->isHit());
    }

    public function testPruneUnlinksUnderThePrefix(): void
    {
        $this->write('gone', 'v', 'red');
        $this->write('kept', 'v', 'blue');
        self::assertTrue($this->pool($this->other)->invalidateTags(['red']));

        self::assertTrue($this->pool($this->reads)->prune());

        self::assertSame(0, $this->exists($this->prefix.self::NAMESPACE.':gone'), 'the invalidated item is gone');
        self::assertSame(1, $this->exists($this->prefix.self::NAMESPACE.':kept'), 'and the other stays');
        self::assertTrue($this->pool($this->reads)->getItem('kept')->isHit());
    }

    public function testWritesAndDeletesGoStraightToTheMasterOfTheirKey(): void
    {
        if (!$this->raw instanceof \RedisCluster && !$this->raw instanceof \Relay\Cluster) {
            self::markTestSkipped('Only a cluster redirects a command sent to another master.');
        }

        // Relay follows a MOVED where phpredis throws: a command routed by the
        // wrong key still lands there, one round trip late, and only the
        // masters' count of what they refused shows it
        $before = $this->refusedWrites();

        for ($i = 0; $i < 12; ++$i) {
            $key = 'routed'.$i;

            // an item this pool never read: a plain write, then a delete
            $item = $this->pool($this->other)->getItem($key);
            self::assertTrue($this->pool($this->redis)->save($item->set('v')));
            self::assertTrue($this->pool($this->redis)->deleteItem($key));
        }

        self::assertSame($before, $this->refusedWrites(), 'no master was sent a write or a delete of a key it does not own');
    }

    public function testPruneWalksEveryMasterOfACluster(): void
    {
        if (!$this->raw instanceof \RedisCluster && !$this->raw instanceof \Relay\Cluster) {
            self::markTestSkipped('Only a cluster has masters to walk one by one.');
        }

        // a master named to scan() by the wrong shape is hashed as a key,
        // prefix included: under several prefixes, one would miss a master
        $prefixes = ['', $this->prefix];

        for ($i = 0; $i < 4; ++$i) {
            $prefixes[] = 'pfx'.bin2hex(random_bytes(3)).':';
        }

        try {
            foreach ($prefixes as $prefix) {
                $pool = $this->pool(Connection::open(prefix: $prefix));

                // enough keys that every master holds some
                for ($i = 0; $i < 24; ++$i) {
                    self::assertTrue($pool->save($pool->getItem('spread'.$i)->set('v')->tag('red')));
                }

                $before = $this->keysByMaster($prefix.self::NAMESPACE.':spread*');
                self::assertSame(24, array_sum($before));
                self::assertNotContains(0, $before, 'invalidated items on every master');

                self::assertTrue($pool->invalidateTags(['red']));
                self::assertTrue($this->pool(Connection::openPrimaries($prefix))->prune());

                self::assertSame(0, array_sum($this->keysByMaster($prefix.self::NAMESPACE.':spread*')), \sprintf('prefix "%s": every invalidated item is gone', $prefix));
            }
        } finally {
            foreach ($prefixes as $prefix) {
                Connection::sweep($this->raw, $prefix.self::NAMESPACE.':*');
            }
        }
    }

    public function testTwoPrefixesOnOneServerShareNoRuleSet(): void
    {
        $shared = new ArrayAdapter();
        $otherPrefix = 'pfx'.bin2hex(random_bytes(3)).':';
        $neighbour = Connection::openPrimaries($otherPrefix);

        try {
            $mine = fn (): VersionedRedisTagAwareAdapter => new VersionedRedisTagAwareAdapter($this->reads, self::NAMESPACE, rulesCache: $shared);
            $theirs = new VersionedRedisTagAwareAdapter($neighbour, self::NAMESPACE, rulesCache: $shared);

            $pool = $mine();
            self::assertTrue($pool->save($pool->getItem('k')->set('before')->tag('t')));
            self::assertTrue($mine()->invalidateTags(['t']));

            // the neighbour's stream holds no rule; its set is read after the
            // invalidation above, so it would pass for fresh under one entry
            self::assertFalse($theirs->getItem('k')->isHit());

            self::assertFalse($mine()->getItem('k')->isHit(), 'a fresh pool adopts the set of its own stream');
        } finally {
            Connection::sweep($this->raw, $otherPrefix.'*');
        }
    }

    private function pool(\Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis, int $window = 60): VersionedRedisTagAwareAdapter
    {
        return new VersionedRedisTagAwareAdapter($redis, self::NAMESPACE, rulesCacheMs: 0, rulesCache: false, guardWindowSeconds: $window);
    }

    private function write(string $key, string $value, string $tag): void
    {
        $pool = $this->pool($this->redis);

        self::assertSame($value, $pool->get($key, static function (ItemInterface $item) use ($value, $tag): string {
            $item->tag($tag);

            return $value;
        }));
    }

    private function exists(string $key): int
    {
        $exists = $this->raw->exists($key);

        return \is_int($exists) ? $exists : (int) (bool) $exists;
    }

    /**
     * How many write and delete commands the masters refused, all told: a
     * command sent to a master that does not own its key is refused with
     * MOVED and counted as rejected.
     */
    private function refusedWrites(): int
    {
        \assert($this->raw instanceof \RedisCluster || $this->raw instanceof \Relay\Cluster);

        $refused = 0;

        foreach ($this->raw->_masters() as $master) {
            if (!\is_array($master)) {
                continue;
            }

            $stats = $this->raw->info($master, 'commandstats');

            foreach (['hsetex', 'hdel', 'pexpire', 'expire'] as $command) {
                $line = \is_array($stats) ? $stats['cmdstat_'.$command] ?? null : null;

                if (\is_string($line) && 1 === preg_match('/rejected_calls=(\d+)/', $line, $match)) {
                    $refused += (int) $match[1];
                }
            }
        }

        return $refused;
    }

    /**
     * How many keys match $pattern on each master, as the server names them.
     *
     * @return list<int>
     */
    private function keysByMaster(string $pattern): array
    {
        \assert($this->raw instanceof \RedisCluster || $this->raw instanceof \Relay\Cluster);

        $counts = [];

        foreach ($this->raw->_masters() as $master) {
            if (!\is_array($master)) {
                continue;
            }

            $count = 0;
            $cursor = null;

            do {
                $keys = $this->raw->scan($cursor, $master, $pattern, 500);
                $count += \is_array($keys) ? \count($keys) : 0;
            } while ($cursor);

            $counts[] = $count;
        }

        return $counts;
    }

    /**
     * @return list<string>
     */
    private function fields(string $key): array
    {
        $fields = $this->raw->hGetAll($key);

        return \is_array($fields) ? array_map(strval(...), array_keys($fields)) : [];
    }
}
