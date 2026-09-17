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
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Tests\Adapter\AdapterTestCase;
use Symfony\Component\Cache\Tests\Adapter\TagAwareTestTrait;

/**
 * Symfony's own conformance suite, the one RedisTagAwareAdapter has to pass,
 * run against this adapter: AdapterTestCase for the pool, TagAwareTestTrait
 * for the tags, exactly as RedisTagAwareAdapterTest combines them upstream.
 * Anything it finds is a real difference in behaviour, not a disagreement
 * about style.
 *
 * The skipped case below is the price of the model, and it is in the README:
 * memory comes back when a reader unlinks a stale item rather than the moment
 * it is invalidated, so the pool is not Pruneable.
 *
 * @group integration
 */
final class VersionedRedisTagAwareAdapterTest extends AdapterTestCase
{
    use TagAwareTestTrait;

    protected array $skippedTests = [
        'testPrune' => 'Redis expires items itself, so this pool is not Pruneable. Neither is the RedisTagAwareAdapter it replaces.',
    ];

    private static \Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis;

    public static function setUpBeforeClass(): void
    {
        self::$redis = Connection::open();
    }

    public function createCachePool(int $defaultLifetime = 0, ?string $testMethod = null): CacheItemPoolInterface
    {
        return new VersionedRedisTagAwareAdapter(self::$redis, 'conformance', $defaultLifetime, rulesCache: false);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // a logical clear leaves the items in place, so sweep what this
        // suite wrote rather than leaving it on someone else's Redis
        Connection::sweep(self::$redis, 'conformance*');
    }
}
