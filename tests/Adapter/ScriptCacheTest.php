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

/**
 * A write that follows no read is a plain HSETEX, and the appended rule
 * travels as a body; the write that follows a read is sent by sha1 and sent
 * again as a body where the server has forgotten it. A SCRIPT FLUSH, a restart
 * or a failover must change nothing, and this is where that is made sure of
 * rather than assumed.
 */
final class ScriptCacheTest extends TestCase
{
    private \Redis|\Relay\Relay $redis;

    protected function setUp(): void
    {
        $this->redis = Connection::openSingleNode();
        $this->clean();
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            $this->clean();
        }
    }

    public function testAFlushedScriptCacheChangesNothing(): void
    {
        $pool = new VersionedRedisTagAwareAdapter($this->redis, 'scripts-test', rulesCacheMs: 0, rulesCache: false);

        $first = $pool->getItem('before');
        $first->set('written before the flush');
        $first->tag(['articles']);
        self::assertTrue($pool->save($first));
        self::assertTrue($pool->invalidateTags(['unrelated']));

        // the server forgets every script it was holding
        $this->redis->script('flush');

        $second = $pool->getItem('after');
        $second->set('written after the flush');
        $second->tag(['articles']);
        self::assertTrue($pool->save($second));

        self::assertSame('written before the flush', $pool->getItem('before')->get());
        self::assertSame('written after the flush', $pool->getItem('after')->get());

        self::assertTrue($pool->invalidateTags(['articles']), 'the rule is still appended');
        self::assertFalse($pool->getItem('before')->isHit(), 'and still takes effect');
        self::assertFalse($pool->getItem('after')->isHit());
    }

    public function testAFlushBetweenAReadAndItsWriteChangesNothing(): void
    {
        $pool = new VersionedRedisTagAwareAdapter($this->redis, 'scripts-test', rulesCacheMs: 0, rulesCache: false);
        $warm = $pool->getItem('warm');
        self::assertTrue($pool->save($warm->set('the script is held now')));

        $value = $pool->get('armed', function (): string {
            // the server forgets the script while the value is computed
            $this->redis->script('flush');

            return 'written after the flush';
        });

        self::assertSame('written after the flush', $value);
        self::assertSame('written after the flush', $pool->getItem('armed')->get(), 'the guarded write landed all the same');
    }

    private function clean(): void
    {
        Connection::sweep($this->redis, 'scripts-test*');
    }
}
