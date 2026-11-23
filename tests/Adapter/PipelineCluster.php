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
 * A cluster client as phpredis has one once cluster pipelines ship, on any
 * release: it has pipeline(), and multi(Redis::PIPELINE) queues the calls
 * across slots until exec() sends them together (see FakePipeline). As there,
 * a raw command is refused while it is queued, and the pipeline stays open.
 */
final class PipelineCluster extends \RedisCluster
{
    use FakePipeline {
        exec as private execQueued;
    }
    use FakeServer;

    /** How many reads went out one at a time */
    public int $direct = 0;

    /** How many raw commands were sent, outside a pipeline */
    public int $raw = 0;

    /** How many typed HSETEX writes were sent or queued */
    public int $typed = 0;

    /** How many of the next exec() calls throw after their calls ran, as on a redirect */
    public int $execFailures = 0;

    /** A failing exec() leaves the pipeline open, which phpredis does not */
    public bool $failureLeavesPipelineOpen = false;

    /** How long a failing exec() takes before it throws, as a node that does not answer */
    public int $failureTakesMs = 0;

    /** Runs once inside the next failing exec(), after its calls ran: what others do meanwhile */
    public ?\Closure $whileFailing = null;

    /** The read timeout the client answers for, in seconds; 0 for none */
    public float $readTimeout = 0.0;

    /** Runs once when the next raw command is refused: what others do meanwhile, outside this pipeline */
    public ?\Closure $whileRefusing = null;

    /** @var array<string, string> the scripts sent as a body, by sha1 */
    private array $scripts = [];

    public function __construct()
    {
        // no seeds, no connection: everything below answers from memory
    }

    public function pipeline(): static
    {
        return $this->multi(\Redis::PIPELINE);
    }

    public function multi(int $value = \Redis::MULTI): static
    {
        return $this->openPipeline();
    }

    /**
     * @return list<mixed>
     */
    public function exec(): array
    {
        if (0 === $this->execFailures) {
            return $this->execQueued();
        }

        --$this->execFailures;
        ++$this->execs;
        usleep($this->failureTakesMs * 1000);

        if (!$this->failureLeavesPipelineOpen) {
            $this->queued = null;
        }

        if (null !== $meanwhile = $this->whileFailing) {
            $this->whileFailing = null;
            $meanwhile();
        }

        throw new \RedisClusterException('Pipelined commands were redirected, aborting pipeline');
    }

    public function getOption(int $option): int|float|null
    {
        return match ($option) {
            \RedisCluster::OPT_SLAVE_FAILOVER => \RedisCluster::FAILOVER_NONE,
            \Redis::OPT_READ_TIMEOUT => $this->readTimeout,
            default => null,
        };
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function xrange(string $key, string $start, string $end, int $count = -1): array
    {
        return $this->range($start, $count, true);
    }

    /**
     * @param array<mixed> $args
     */
    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        $this->scripts[sha1($script)] = $script;

        return $this->answer($this->evaluate($script, $args));
    }

    /**
     * Forgets every script, as a restart or a failover does.
     */
    public function flushScripts(): void
    {
        $this->scripts = [];
    }

    /**
     * Holds a script once it was sent as a body, as a server does.
     *
     * @param array<mixed> $args
     */
    public function evalsha(string $sha1, array $args = [], int $num_keys = 0): mixed
    {
        $script = $this->scripts[$sha1] ?? null;

        return $this->answer(null === $script ? false : $this->evaluate($script, $args));
    }

    /**
     * @param array<mixed> $keys
     *
     * @return static|array<string, string|false>
     */
    public function hmget(string $key, array $keys): static|array
    {
        if (null === $this->queued) {
            ++$this->direct;
        }

        return $this->answer($this->row($key, $keys));
    }

    /**
     * One master, for a prune to walk.
     *
     * @return list<array{string, int}>
     */
    public function _masters(): array
    {
        return [['127.0.0.1', 7000]];
    }

    /**
     * Every item matching $pattern, in one answer.
     *
     * @param array<mixed>|string $key_or_address
     *
     * @param-out int $iterator
     *
     * @return list<string>
     */
    public function scan(string|int|null &$iterator, array|string $key_or_address, ?string $pattern = null, int $count = 0): array
    {
        $iterator = 0;
        $keys = array_map(strval(...), array_keys($this->items));

        return array_values(array_filter($keys, static fn (string $key): bool => null === $pattern || fnmatch($pattern, $key)));
    }

    /**
     * @param array<mixed>|string $key
     */
    public function unlink(array|string $key, string ...$other_keys): int
    {
        return $this->forget($key, ...$other_keys);
    }

    /**
     * Refused inside a pipeline, as phpredis refuses every command addressed
     * by key or node there. Outside one, HSETEX and HDEL change the item and
     * an expiry is taken as set.
     *
     * @param array<mixed>|string $key_or_address
     */
    public function rawcommand(array|string $key_or_address, string $command, mixed ...$args): mixed
    {
        if (null !== $this->queued) {
            if (null !== $meanwhile = $this->whileRefusing) {
                // the others' commands are not part of this pipeline
                $this->whileRefusing = null;
                $queued = $this->queued;
                $this->queued = null;
                $meanwhile();
                $this->queued = $queued;
            }

            throw new \RedisClusterException("Directed node commands can't be issued in PIPELINE mode");
        }

        ++$this->raw;
        $strings = array_map(static fn (mixed $arg): string => \is_scalar($arg) ? (string) $arg : '', $args);
        $key = array_shift($strings) ?? '';

        if ('HSETEX' === $command) {
            // KEY PX <ms> FIELDS <n> field value ...
            $pairs = \array_slice($strings, 4);

            for ($i = 0; $i + 1 < \count($pairs); $i += 2) {
                $this->items[$key][$pairs[$i]] = $pairs[$i + 1];
            }

            return 1;
        }

        if ('HDEL' === $command) {
            foreach ($strings as $field) {
                unset($this->items[$key][$field]);
            }

            return \count($strings);
        }

        return 'HPEXPIRE' === $command ? [1] : 1;
    }

    /**
     * @param array<mixed>      $fields
     * @param array<mixed>|null $expiry
     */
    public function hsetex(string $key, array $fields, ?array $expiry = null): static|int
    {
        ++$this->typed;

        foreach ($fields as $field => $value) {
            $this->items[$key][(string) $field] = \is_scalar($value) ? (string) $value : '';
        }

        return $this->answer(1);
    }

    public function expire(string $key, int $timeout, ?string $mode = null): static|bool
    {
        return $this->answer(true);
    }

    public function hdel(string $key, string $member, string ...$other_members): static|int
    {
        foreach ([$member, ...$other_members] as $field) {
            unset($this->items[$key][$field]);
        }

        return $this->answer(1 + \count($other_members));
    }

    /**
     * @param array<mixed> $fields
     *
     * @return static|list<int>
     */
    public function hpexpire(string $key, int $ttl, array $fields, ?string $mode = null): static|array
    {
        return $this->answer(array_fill(0, \count($fields), 1));
    }
}
