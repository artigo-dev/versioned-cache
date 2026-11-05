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
 * How the write that follows a read reaches the server: by sha1, as EVALSHA,
 * and as a body only where the server does not hold the script - after a
 * flush, a restart or a failover - and then only for the ids it refused.
 */
final class GuardedWriteScriptTest extends TestCase
{
    private SpyRedis $redis;

    protected function setUp(): void
    {
        $this->redis = Connection::openSingleNodeAs(SpyRedis::class);
        Connection::sweep($this->redis, 'gws*');
    }

    protected function tearDown(): void
    {
        if (isset($this->redis)) {
            Connection::sweep($this->redis, 'gws*');
        }
    }

    public function testAGuardedWriteIsSentBySha(): void
    {
        $pool = $this->pool();
        $this->redis->script('flush');

        $first = $pool->getItem('first');
        $this->redis->scripts = [];
        self::assertTrue($pool->save($first->set('v')));

        self::assertSame([['evalsha', 'gws:first'], ['eval', 'gws:first']], $this->redis->scripts, 'the server did not hold it: sent once more as a body');

        $second = $pool->getItem('second');
        $this->redis->scripts = [];
        self::assertTrue($pool->save($second->set('v')));

        self::assertSame([['evalsha', 'gws:second']], $this->redis->scripts, 'now it does');
    }

    public function testAPipelineSendsAgainOnlyWhatTheServerDidNotHold(): void
    {
        $pool = $this->pool();
        // the server holds the script
        $warm = $pool->getItem('warm');
        self::assertTrue($pool->save($warm->set('v')));

        $items = iterator_to_array($pool->getItems(['a', 'b']));
        $this->redis->scripts = [];
        $this->redis->spoilNextSha = true;

        foreach ($items as $item) {
            $pool->saveDeferred($item->set('v'));
        }

        self::assertTrue($pool->commit());
        self::assertSame([['evalsha', 'gws:a'], ['evalsha', 'gws:b'], ['eval', 'gws:a']], $this->redis->scripts);
        self::assertTrue($pool->getItem('a')->isHit());
        self::assertTrue($pool->getItem('b')->isHit());
    }

    public function testAClientThatRaisesOnAForgottenScriptStillWrites(): void
    {
        $pool = $this->pool();
        $items = iterator_to_array($pool->getItems(['a', 'b']));
        $this->redis->script('flush');
        $this->redis->execRaisesOnAForgottenScript = true;
        $this->redis->scripts = [];

        foreach ($items as $item) {
            $pool->saveDeferred($item->set('v'));
        }

        self::assertTrue($pool->commit(), 'sent again one at a time, then as bodies');
        self::assertSame([['evalsha', 'gws:a'], ['evalsha', 'gws:b'], ['evalsha', 'gws:a'], ['evalsha', 'gws:b'], ['eval', 'gws:a'], ['eval', 'gws:b']], $this->redis->scripts);
        self::assertTrue($pool->getItem('a')->isHit());
        self::assertTrue($pool->getItem('b')->isHit());
    }

    private function pool(): VersionedRedisTagAwareAdapter
    {
        return new VersionedRedisTagAwareAdapter($this->redis, 'gws', rulesCacheMs: 0, rulesCache: false);
    }
}
