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
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * A RedisArray picks a node by key for a typed call, but sends a script to
 * wherever its first argument - the body - hashes. Every command that names
 * the rules stream has to reach the node that owns it, or a rule lands where
 * no reader looks; every raw command has to reach the node that owns its key.
 *
 * The two nodes are one server under two host names, the second switched to
 * another database (see Connection::openArray()), so a command sent to the
 * wrong one is not seen by the right one. Each case runs once per node, with
 * the rules stream owned by it: the script body hashes to one of them, so one
 * of the two is a node the body does not name.
 */
final class RedisArrayTest extends TestCase
{
    private \RedisArray $array;

    /** @var list<string> */
    private array $namespaces = [];

    protected function setUp(): void
    {
        if (!class_exists(\RedisArray::class)) {
            self::markTestSkipped('ext-redis has no RedisArray here.');
        }

        $this->array = Connection::openArray();
    }

    protected function tearDown(): void
    {
        if (!isset($this->array)) {
            return;
        }

        foreach (Connection::nodesOf($this->array) as $node) {
            foreach ($this->namespaces as $namespace) {
                Connection::sweep($node, '*'.$namespace.':*');
            }
        }
    }

    public function testAnInvalidationReachesAFreshPool(): void
    {
        foreach ($this->namespacesByOwner() as $owner => $namespace) {
            $writer = $this->pool(Connection::openArray(), $namespace);
            self::assertTrue($writer->save($writer->getItem('k')->set('before')->tag('t')));
            self::assertTrue($writer->invalidateTags(['t']));

            self::assertFalse($this->pool(Connection::openArray(), $namespace)->getItem('k')->isHit(), \sprintf('the rule is on %s, where a reader looks', $owner));
            self::assertSame(1, $this->streamLength($owner, $namespace));
        }
    }

    public function testAClearReachesAFreshPool(): void
    {
        foreach ($this->namespacesByOwner() as $owner => $namespace) {
            $writer = $this->pool(Connection::openArray(), $namespace);
            self::assertTrue($writer->save($writer->getItem('k')->set('before')));
            self::assertTrue($writer->clear());

            self::assertFalse($this->pool(Connection::openArray(), $namespace)->getItem('k')->isHit(), \sprintf('the clear is on %s, where a reader looks', $owner));
        }
    }

    public function testAWriteStampedAfterARuleIsFresh(): void
    {
        foreach ($this->namespacesByOwner() as $owner => $namespace) {
            self::assertTrue($this->pool(Connection::openArray(), $namespace)->invalidateTags(['t']));

            // a write with no read before it is stamped with the stream's head
            $item = $this->pool(Connection::openArray(), $namespace)->getItem('k');
            self::assertTrue($this->pool(Connection::openArray(), $namespace)->save($item->set('after')->tag('t')));

            self::assertTrue($this->pool(Connection::openArray(), $namespace)->getItem('k')->isHit(), \sprintf('the head was read on %s', $owner));
        }
    }

    public function testRawCommandsReachTheNodeOfTheirKeyUnderAPrefix(): void
    {
        $prefix = 'pfx'.bin2hex(random_bytes(3)).':';

        foreach ($this->namespacesByOwner() as $namespace) {
            $writer = $this->pool(Connection::openArray($prefix), $namespace);

            // a plain write: an item this pool never read
            $item = $this->pool(Connection::openArray($prefix), $namespace)->getItem('k');
            self::assertTrue($writer->save($item->set('written')));
            self::assertSame('written', $this->pool(Connection::openArray($prefix), $namespace)->getItem('k')->get());

            self::assertTrue($writer->deleteItem('k'));
            self::assertFalse($this->pool(Connection::openArray($prefix), $namespace)->getItem('k')->isHit(), 'the delete stands');

            $owner = $this->array->_target($namespace.':k');
            $node = \is_string($owner) ? Connection::nodesOf($this->array)[$owner] ?? null : null;
            self::assertInstanceOf(\Redis::class, $node);
            self::assertSame(['e'], array_keys((array) $node->hGetAll($prefix.$namespace.':k')), 'a tombstone under the prefix, on the node of the key');
            self::assertSame(0, $node->exists($namespace.':k'), 'and nothing outside it');
        }
    }

    public function testPruneUnderAPrefixFindsTheKeysOfEveryNodeWhetherOrNotTheNodesPrefixTheScan(): void
    {
        if (!\defined('Redis::SCAN_PREFIX')) {
            self::markTestSkipped('This ext-redis has no SCAN_PREFIX.');
        }

        foreach (['off' => false, 'on' => true] as $label => $scanPrefix) {
            $prefix = 'pfx'.bin2hex(random_bytes(3)).':';
            $namespace = 'arrayprune'.bin2hex(random_bytes(3));
            $this->namespaces[] = $namespace;
            $array = Connection::openArray($prefix);

            if ($scanPrefix) {
                // the array answers this option once per node too
                foreach (Connection::nodesOf($array) as $node) {
                    $node->setOption(\Redis::OPT_SCAN, \Redis::SCAN_PREFIX);
                }
            }

            $pool = $this->pool($array, $namespace);

            // enough keys that both nodes hold some
            for ($i = 0; $i < 24; ++$i) {
                self::assertTrue($pool->save($pool->getItem('gone'.$i)->set('v')->tag('t')));
            }

            self::assertTrue($pool->save($pool->getItem('kept')->set('v')));
            self::assertTrue($pool->invalidateTags(['t']));

            $before = $this->keysByNode($prefix.$namespace.':gone*');
            self::assertSame(24, array_sum($before));
            self::assertNotContains(0, $before, 'invalidated items on every node');

            self::assertTrue($pool->prune());

            self::assertSame(0, array_sum($this->keysByNode($prefix.$namespace.':gone*')), \sprintf('SCAN_PREFIX %s: every invalidated item is gone', $label));
            self::assertSame(1, array_sum($this->keysByNode($prefix.$namespace.':kept')), \sprintf('SCAN_PREFIX %s: and the one no rule names is not', $label));
        }
    }

    public function testArraysOnOtherDatabasesShareNoRuleSet(): void
    {
        $namespace = 'arraydb'.bin2hex(random_bytes(3));
        $this->namespaces[] = $namespace;
        $mine = Connection::openArray();
        $theirs = Connection::openArray();

        // the same hosts, every node of theirs switched to a database of its own
        $databases = [];

        foreach (array_values(Connection::nodesOf($theirs)) as $index => $node) {
            self::assertTrue($node->select(11 + $index));
            $databases[] = 11 + $index;
        }

        foreach (Connection::nodesOf($mine) as $node) {
            self::assertNotContains($node->getDbNum(), $databases, 'mine are other databases');
        }

        try {
            // an item of theirs, and a rule of theirs that invalidates it
            $writer = $this->pool($theirs, $namespace);
            self::assertTrue($writer->save($writer->getItem('k')->set('theirs')->tag('t')));
            self::assertTrue($this->pool($theirs, $namespace)->invalidateTags(['t']));

            // my rules, kept where every pool of this server keeps its set
            $medium = new ArrayAdapter();
            $pool = new VersionedRedisTagAwareAdapter($mine, $namespace, rulesCache: $medium);
            self::assertTrue($pool->invalidateTags(['x']));
            $pool->getItem('anything');

            $item = (new VersionedRedisTagAwareAdapter($theirs, $namespace, rulesCache: $medium))->getItem('k');

            self::assertFalse($item->isHit(), 'their own rule stands: the set shared for my databases is not theirs');
        } finally {
            foreach (Connection::nodesOf($theirs) as $node) {
                Connection::sweep($node, '*'.$namespace.':*');
            }
        }
    }

    /**
     * How many keys match $pattern on each node, as the server names them.
     *
     * @return array<string, int> host => keys
     */
    private function keysByNode(string $pattern): array
    {
        $counts = [];

        foreach (Connection::nodesOf($this->array) as $host => $node) {
            $keys = $node->keys($pattern);
            $counts[$host] = \is_array($keys) ? \count($keys) : 0;
        }

        return $counts;
    }

    private function pool(\RedisArray $array, string $namespace): VersionedRedisTagAwareAdapter
    {
        return new VersionedRedisTagAwareAdapter($array, $namespace, rulesCacheMs: 0, rulesCache: false);
    }

    /**
     * One namespace per node, the rules stream of each owned by that node.
     *
     * @return array<string, string> node => namespace
     */
    private function namespacesByOwner(): array
    {
        $byOwner = [];
        $run = bin2hex(random_bytes(3));
        $nodes = \count(Connection::nodesOf($this->array));

        for ($i = 0; $i < 64 && \count($byOwner) < $nodes; ++$i) {
            $namespace = 'array'.$run.$i;
            $owner = $this->array->_target($namespace.':@rules');

            if (\is_string($owner) && !isset($byOwner[$owner])) {
                $byOwner[$owner] = $namespace;
                $this->namespaces[] = $namespace;
            }
        }

        self::assertCount(2, $byOwner, 'a rules stream on each node');

        return $byOwner;
    }

    private function streamLength(string $owner, string $namespace): int
    {
        $length = (Connection::nodesOf($this->array)[$owner] ?? null)?->xLen($namespace.':@rules');

        return \is_int($length) ? $length : 0;
    }
}
