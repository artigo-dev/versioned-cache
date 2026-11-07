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
 * A connection that remembers where each XRANGE started, how many entries it
 * asked for, and how often the stream's head was asked for.
 */
final class SpyRedis extends \Redis
{
    /** @var list<string> */
    public array $starts = [];

    /** @var list<int> */
    public array $counts = [];

    public int $watermarks = 0;

    public int $times = 0;

    /** @var list<array{string, string}> each script sent: [evalsha or eval, its key] */
    public array $scripts = [];

    /** The next EVALSHA names a script the server cannot hold */
    public bool $spoilNextSha = false;

    /** A pipeline with an unanswered EVALSHA raises at exec(), as a client may */
    public bool $execRaisesOnAForgottenScript = false;

    /** XRANGE raises, as when the node holding the stream cannot be reached */
    public bool $rangesFail = false;

    /** How long an XRANGE that raises takes first, as a read timeout does */
    public int $failAfterMs = 0;

    /** How long an XRANGE takes before it answers, as a slow node does */
    public int $answerAfterMs = 0;

    /** XRANGE answers false, as phpredis does on an error reply (BUSY, LOADING) */
    public bool $rangesAnswerFalse = false;

    /** Runs once, inside the next XRANGE: what other workers do while it is in flight */
    public ?\Closure $duringRange = null;

    /**
     * Forgets the ranges asked for so far.
     */
    public function forgetRanges(): void
    {
        $this->starts = [];
        $this->counts = [];
    }

    /**
     * @return \Redis|array<mixed>|bool
     */
    public function xRange(string $key, string $start, string $end, int $count = -1): \Redis|array|bool
    {
        $this->starts[] = $start;
        $this->counts[] = $count;

        if (null !== $during = $this->duringRange) {
            $this->duringRange = null;
            $during();
        }

        if ($this->rangesFail) {
            usleep($this->failAfterMs * 1000);

            throw new \RedisException('the node holding the rules stream is down');
        }

        if ($this->rangesAnswerFalse) {
            return false;
        }

        usleep($this->answerAfterMs * 1000);

        return parent::xRange($key, $start, $end, $count);
    }

    /**
     * @param array<mixed> $args
     */
    public function evalsha(string $sha1, array $args = [], int $num_keys = 0): mixed
    {
        $this->scripts[] = ['evalsha', \is_string($args[0] ?? null) ? $args[0] : ''];

        if ($this->spoilNextSha) {
            $this->spoilNextSha = false;
            $sha1 = str_repeat('0', 40);
        }

        return parent::evalsha($sha1, $args, $num_keys);
    }

    /**
     * @param array<mixed> $args
     */
    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        $this->scripts[] = ['eval', \is_string($args[0] ?? null) ? $args[0] : ''];

        return parent::eval($script, $args, $num_keys);
    }

    /**
     * @return \Redis|array<mixed>|false
     */
    public function exec(): \Redis|array|false
    {
        $results = parent::exec();

        if ($this->execRaisesOnAForgottenScript && \is_array($results) && \in_array(false, $results, true)) {
            throw new \RedisException('NOSCRIPT No matching script. Please use EVAL.');
        }

        return $results;
    }

    /**
     * @return \Redis|array<mixed>
     */
    public function time(): \Redis|array
    {
        ++$this->times;

        return parent::time();
    }

    /**
     * @return \Redis|array<mixed>|bool
     */
    public function xRevRange(string $key, string $end, string $start, int $count = -1): \Redis|array|bool
    {
        ++$this->watermarks;

        return parent::xRevRange($key, $end, $start, $count);
    }
}
