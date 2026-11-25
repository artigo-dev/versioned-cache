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
 * A cluster client with no cluster behind it, as the phpredis in use has one.
 * A subclass inherits pipeline() where the release has it, so it pipelines
 * there (see FakePipeline); phpredis 6.3 has none, and every call goes out on
 * its own. Reads come from a replica where $failover says so (see FakeServer).
 */
final class FakeCluster extends \RedisCluster
{
    use FakePipeline;
    use FakeServer;

    /** How many reads went out one at a time */
    public int $direct = 0;

    /** How many raw commands were sent or queued */
    public int $raw = 0;

    /** @var list<array{string, int, array<mixed>, ?string}> the typed field expiries sent: method, TTL, fields, condition */
    public array $fieldExpiries = [];

    /** How many scripts were sent by sha1, and as a body */
    public int $evalshas = 0;

    public int $evals = 0;

    /** How many times the connection was closed */
    public int $closes = 0;

    /** The read timeout the client reports, in seconds */
    public float $readTimeout = 0.0;

    /** How many of the next commands throw, as a node that does not answer, and how long each takes first */
    public int $failures = 0;

    public int $failureTakesMs = 0;

    /** The command that throws instead of the next one, where set: hdel, say */
    public ?string $failureOn = null;

    /** A failing command throws as for a reply cut short (\RedisException), not as a typed command that failed */
    public bool $failureCutsAReply = false;

    /** HSETEX answers false, as a server out of memory refuses it, and HDEL still runs */
    public bool $outOfMemory = false;

    /** @var array<string, true> keys holding a value of another type than a hash */
    public array $strings = [];

    /** An HSETEX that sets this field answers false, the others land */
    public ?string $refusedField = null;

    /** HSETEX and HDEL answer false, as for an error reply (READONLY, LOADING, NOPERM) */
    public bool $refusesHashWrites = false;

    /** UNLINK answers false, as for an error reply */
    public bool $unlinkAnswersFalse = false;

    public function __construct(private readonly int $failover = \RedisCluster::FAILOVER_NONE)
    {
        // no seeds, no connection: everything below answers from memory
    }

    public function getOption(int $option): int|float|null
    {
        return match ($option) {
            \RedisCluster::OPT_SLAVE_FAILOVER => $this->failover,
            \Redis::OPT_READ_TIMEOUT => $this->readTimeout,
            default => null,
        };
    }

    public function close(): bool
    {
        ++$this->closes;

        return true;
    }

    public function getlasterror(): ?string
    {
        return null;
    }

    public function clearlasterror(): bool
    {
        return true;
    }

    public function type(string $key): static|int
    {
        return $this->answer(match (true) {
            isset($this->strings[$key]) => \Redis::REDIS_STRING,
            isset($this->items[$key]) => \Redis::REDIS_HASH,
            default => \Redis::REDIS_NOT_FOUND,
        });
    }

    /**
     * The server's clock, asked of the node that holds the key, as phpredis
     * sends TIME on a cluster.
     *
     * @param array<mixed>|string $key_or_address
     *
     * @return list<string>
     */
    public function time(array|string $key_or_address): array
    {
        $this->mayFail('time');

        return [(string) time(), '0'];
    }

    /**
     * Throws, after failureTakesMs, while failures are left.
     */
    private function mayFail(string $method): void
    {
        if (0 === $this->failures || (null !== $this->failureOn && $method !== $this->failureOn)) {
            return;
        }

        --$this->failures;
        usleep($this->failureTakesMs * 1000);

        if ($this->failureCutsAReply) {
            throw new \RedisException('socket error on read socket');
        }

        throw new \RedisClusterException('Error processing response from Redis node!');
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function xrange(string $key, string $start, string $end, int $count = -1): array
    {
        $this->mayFail('xrange');

        return $this->range($start, $count, \RedisCluster::FAILOVER_NONE === $this->failover);
    }

    /**
     * @param array<mixed> $args
     */
    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        ++$this->evals;
        $this->mayFail('eval');

        return $this->answer($this->evaluate($script, $args));
    }

    /**
     * Holds no script: every one is sent again as a body.
     *
     * @param array<mixed> $args
     */
    public function evalsha(string $sha1, array $args = [], int $num_keys = 0): mixed
    {
        ++$this->evalshas;
        $this->mayFail('evalsha');

        return $this->answer(false);
    }

    /**
     * @param array<mixed> $keys
     *
     * @return static|array<string, string|false>|false
     */
    public function hmget(string $key, array $keys): static|array|false
    {
        if (null === $this->queued) {
            ++$this->direct;
        }

        $this->mayFail('hmget');

        return $this->answer(isset($this->strings[$key]) ? false : $this->row($key, $keys));
    }

    /**
     * @param array<mixed>|string $key
     */
    public function unlink(array|string $key, string ...$other_keys): int|false
    {
        $this->mayFail('unlink');

        if ($this->unlinkAnswersFalse) {
            return false;
        }

        foreach ([...(array) $key, ...$other_keys] as $one) {
            if (\is_string($one)) {
                unset($this->strings[$one]);
            }
        }

        return $this->forget($key, ...$other_keys);
    }

    /**
     * A raw command goes where the last read of its slot went: on a cluster
     * reading from replicas, a replica, which refuses a write (phpredis 6.3).
     *
     * @param array<mixed>|string $key_or_address
     */
    public function rawcommand(array|string $key_or_address, string $command, mixed ...$args): mixed
    {
        if (\RedisCluster::FAILOVER_NONE !== $this->failover) {
            throw new \RedisClusterException('Unable to send command to the specified node');
        }

        $this->mayFail('rawcommand');
        ++$this->raw;

        return $this->answer(1);
    }

    /**
     * Sets the fields given and leaves the others, as HSETEX does. A value of
     * another type, or a server out of memory, refuses it.
     *
     * @param array<mixed>      $fields
     * @param array<mixed>|null $expiry
     */
    public function hsetex(string $key, array $fields, ?array $expiry = null): static|int|false
    {
        $this->mayFail('hsetex');

        if (isset($this->strings[$key]) || $this->outOfMemory || $this->refusesHashWrites || (null !== $this->refusedField && \array_key_exists($this->refusedField, $fields))) {
            return $this->answer(false);
        }

        foreach ($fields as $field => $value) {
            $this->items[$key][(string) $field] = \is_scalar($value) ? (string) $value : '';
        }

        return $this->answer(1);
    }

    public function expire(string $key, int $timeout, ?string $mode = null): static|bool
    {
        $this->mayFail('expire');

        return $this->answer(!isset($this->strings[$key]));
    }

    public function hdel(string $key, string $member, string ...$other_members): static|int|false
    {
        $this->mayFail('hdel');

        if (isset($this->strings[$key]) || $this->refusesHashWrites) {
            return $this->answer(false);
        }

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
    public function hexpire(string $key, int $ttl, array $fields, ?string $mode = null): static|array
    {
        $this->mayFail('hexpire');
        $this->fieldExpiries[] = ['hexpire', $ttl, $fields, $mode];

        return $this->answer(array_fill(0, \count($fields), 1));
    }

    /**
     * Recorded like hexpire(), so a test sees which of the two was sent.
     *
     * @param array<mixed> $fields
     *
     * @return static|list<int>
     */
    public function hpexpire(string $key, int $ttl, array $fields, ?string $mode = null): static|array
    {
        $this->mayFail('hpexpire');
        $this->fieldExpiries[] = ['hpexpire', $ttl, $fields, $mode];

        return $this->answer(array_fill(0, \count($fields), 1));
    }

    /**
     * A pipeline where phpredis has cluster pipelines; counted, and refused,
     * where it has none (6.3, which the adapter does not ask).
     */
    public function multi(int $value = \Redis::MULTI): static|bool
    {
        if (!self::pipelinesClusters()) {
            ++$this->multis;

            return false;
        }

        return $this->openPipeline();
    }
}
