<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests;

use Artigo\Cache\Adapter\VersionedRedisTagAwareAdapter;
use Cache\IntegrationTests\SimpleCacheTest;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Psr16Cache;

/**
 * PSR-16 conformance, through Symfony's own PSR-6 to PSR-16 bridge.
 *
 * The adapter deliberately does not implement Psr\SimpleCache\CacheInterface
 * itself - a pool that answers both contracts has to choose which of the two
 * sets of key rules and return conventions it obeys, and Symfony settled that
 * question years ago with Psr16Cache. What this test is for is the claim that
 * wrapping it works: the suite here is the same one the PHP-FIG cache group
 * publishes, not something written to agree with us.
 *
 * @group integration
 */
final class Psr16Test extends SimpleCacheTest
{
    protected array $skippedTests = [
        'testPrune' => 'Redis expires items itself, so this pool is not Pruneable.',
    ];

    private static \Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis;

    public static function setUpBeforeClass(): void
    {
        self::$redis = Connection::open();
    }

    public function createSimpleCache(): CacheInterface
    {
        return new Psr16Cache(new VersionedRedisTagAwareAdapter(self::$redis, 'psr16'));
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        // a logical clear leaves the items where they are, so sweep what
        // this suite wrote rather than leaving it on someone else's Redis
        Connection::sweep(self::$redis, 'psr16*');
    }
}
