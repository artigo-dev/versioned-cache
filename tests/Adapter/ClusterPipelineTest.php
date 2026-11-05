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
use PHPUnit\Framework\TestCase;

/**
 * A cluster pipelines across its slots only where phpredis has cluster
 * pipelines, which no release has yet (phpredis#2798). Until one does, a
 * 100-key read is 100 round trips, and the cluster CI leg proves that path
 * against real nodes; the capable path is pinned here against a client that
 * has pipeline(), so it is ready when the release is.
 */
final class ClusterPipelineTest extends TestCase
{
    private const KEYS = 100;

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
        $cluster = new FakeCluster();
        self::fill($cluster);

        self::assertSame(self::KEYS, self::hits(new VersionedRedisTagAwareAdapter($cluster, 'pipe', rulesCache: false)));
        self::assertSame(self::KEYS, $cluster->direct, 'one call per key');
        self::assertSame(0, $cluster->multis, 'no pipeline was asked of a client that has none');
    }

    private static function fill(FakeCluster|PipelineCluster $cluster): void
    {
        for ($i = 0; $i < self::KEYS; ++$i) {
            $cluster->storeItem('pipe:k'.$i, 'v', ['t'], '0-0');
        }
    }

    private static function hits(VersionedRedisTagAwareAdapter $pool): int
    {
        $keys = [];

        for ($i = 0; $i < self::KEYS; ++$i) {
            $keys[] = 'k'.$i;
        }

        $hits = 0;

        foreach ($pool->getItems($keys) as $item) {
            $hits += $item->isHit() ? 1 : 0;
        }

        return $hits;
    }
}
