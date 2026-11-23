<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests\Adapter;

/**
 * A cluster pipeline held in memory, for the cluster stand-ins: while one is
 * open, a call answers the client itself and its answer waits for exec(), as
 * phpredis queues it. The answers are worked out when the call is queued, so
 * by exec() every call has run.
 */
trait FakePipeline
{
    /** How many pipelines were asked for */
    public int $multis = 0;

    /** How many pipelines were sent */
    public int $execs = 0;

    /** @var list<mixed>|null the answers queued while a pipeline is open */
    private ?array $queued = null;

    /**
     * Whether phpredis as installed pipelines a cluster: a release with
     * RedisCluster::pipeline() does.
     */
    public static function pipelinesClusters(): bool
    {
        return method_exists(\RedisCluster::class, 'pipeline'); // @phpstan-ignore function.impossibleType (the stubs describe phpredis 6.3, which has none)
    }

    public function getMode(): int
    {
        return null === $this->queued ? \Redis::ATOMIC : \Redis::PIPELINE;
    }

    /**
     * @return list<mixed>
     */
    public function exec(): array
    {
        ++$this->execs;
        $answers = $this->queued ?? [];
        $this->queued = null;

        return $answers;
    }

    public function discard(): bool
    {
        $this->queued = null;

        return true;
    }

    private function openPipeline(): static
    {
        ++$this->multis;
        $this->queued = [];

        return $this;
    }

    /**
     * $answer, or the client itself while a pipeline is open, which keeps the
     * answer for exec().
     *
     * @template T
     *
     * @param T $answer
     *
     * @return T|static
     */
    private function answer(mixed $answer): mixed
    {
        if (null === $this->queued) {
            return $answer;
        }

        $this->queued[] = $answer;

        return $this;
    }
}
