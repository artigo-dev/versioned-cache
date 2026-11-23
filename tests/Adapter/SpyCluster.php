<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests\Adapter;

use Artigo\Cache\Tests\Connection;
use PHPUnit\Framework\Assert;
use Symfony\Component\Cache\Adapter\RedisAdapter;

/**
 * A phpredis cluster client on the cluster the suite runs against, counting
 * the pipelines it is asked for and the writes it is sent. exec() can be told
 * to throw after the pipeline ran, as phpredis does when one of its commands
 * was redirected while the others were carried out.
 */
final class SpyCluster extends \RedisCluster
{
    /** How many pipelines were asked for */
    public int $multis = 0;

    /** How many pipelines were sent */
    public int $execs = 0;

    /** How many raw commands were sent, outside a pipeline */
    public int $raw = 0;

    /** How many typed HSETEX writes were sent or queued */
    public int $typed = 0;

    /** How many reads went out one at a time */
    public int $direct = 0;

    /** How many of the next exec() calls throw after their calls ran */
    public int $execFailures = 0;

    /** A failing exec() leaves a pipeline open, which phpredis does not */
    public bool $failureLeavesPipelineOpen = false;

    /**
     * Skips the calling test unless the suite runs against a cluster through
     * a phpredis that pipelines one.
     */
    public static function open(?string $dsn = null): self
    {
        $dsn ??= Connection::dsn();

        if (Connection::isRelay() || 1 !== preg_match('/[?&](?:redis_)?cluster=(?:1|true|on|yes)(?:&|$)/i', $dsn)) {
            Assert::markTestSkipped('This needs a phpredis connection to a cluster.');
        }

        if (!FakeCluster::pipelinesClusters()) {
            Assert::markTestSkipped(\sprintf('phpredis %s has no cluster pipelines.', phpversion('redis')));
        }

        try {
            $cluster = RedisAdapter::createConnection($dsn, ['class' => self::class]);
        } catch (\Throwable $e) {
            Assert::markTestSkipped(\sprintf('Redis is not reachable at "%s": %s', $dsn, $e->getMessage()));
        }

        Assert::assertInstanceOf(self::class, $cluster);

        return $cluster;
    }

    /**
     * The same, reading from the primaries only, as a client the adapter sends
     * raw writes to does: phpredis sends a raw command to the node the last
     * read of its slot went to, which may be a replica.
     */
    public static function openPrimaries(): self
    {
        return self::open((string) preg_replace('/&failover=[a-z_]+/', '', Connection::dsn()));
    }

    public function multi(int $value = \Redis::MULTI): \RedisCluster|bool
    {
        ++$this->multis;

        return parent::multi($value);
    }

    /**
     * @return array<mixed>|false
     */
    public function exec(): array|false
    {
        ++$this->execs;
        $results = parent::exec();

        if (0 < $this->execFailures) {
            --$this->execFailures;

            if ($this->failureLeavesPipelineOpen) {
                parent::multi(\Redis::PIPELINE);
            }

            throw new \RedisClusterException('Pipelined commands were redirected, aborting pipeline');
        }

        return \is_array($results) ? $results : false;
    }

    /**
     * @param array<mixed>|string $key_or_address
     */
    public function rawcommand(array|string $key_or_address, string $command, mixed ...$args): mixed
    {
        if (\Redis::ATOMIC === $this->getMode()) {
            ++$this->raw;
        }

        return parent::rawcommand($key_or_address, $command, ...$args);
    }

    /**
     * @param array<mixed>      $fields
     * @param array<mixed>|null $expiry
     */
    public function hsetex(string $key, array $fields, ?array $expiry = null): \RedisCluster|int|false
    {
        ++$this->typed;

        return parent::hsetex($key, $fields, $expiry);
    }

    /**
     * @param array<mixed> $keys
     *
     * @return \RedisCluster|array<mixed>|false
     */
    public function hmget(string $key, array $keys): \RedisCluster|array|false
    {
        if (\Redis::ATOMIC === $this->getMode()) {
            ++$this->direct;
        }

        return parent::hmget($key, $keys);
    }
}
