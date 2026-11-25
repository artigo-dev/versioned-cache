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
 * A single node held in memory (see FakeServer), whose replies come back
 * through a socket that can keep one, as phpredis 6.3.0's does after a read
 * timeout: a round trip that stalls throws, the server still runs it, and its
 * replies stay on the socket. The next round trip then reads them as its own
 * answers, and every later one is a reply late, until close() drops the
 * socket.
 */
final class LateReplyRedis extends \Redis
{
    use FakeServer;

    /** How many of the next round trips stall */
    public int $stalls = 0;

    /** Only a round trip that sends this command stalls, where set */
    public ?string $stallOn = null;

    /** How many of the round trips that would stall answer first */
    public int $stallsAfter = 0;

    /** How long a stalled round trip takes before it throws, in milliseconds */
    public int $stallMs = 0;

    /** A stalled round trip closes the socket itself, as phpredis 6.4 does */
    public bool $closesOnStall = false;

    /** The read timeout the client reports, in seconds */
    public float $readTimeout = 0.0;

    /** The next round trip is refused with this error reply, which phpredis throws */
    public ?string $errorReply = null;

    /** A stalled round trip throws this error reply instead, as phpredis 6.3 does when the replies after a timed-out one are error replies */
    public ?string $errorAfterStall = null;

    /** The next round trip throws this error reply on top of an earlier failure, its previous exception; its replies stay on the socket */
    public ?string $errorAfterFailure = null;

    /** How many of the next pipelines answer false without throwing, as phpredis 6.3 does when it could not write one whole; their replies stay on the socket */
    public int $execsAnswerFalse = 0;

    /** How many of the next multi() calls throw, as when the connection cannot be opened */
    public int $multiFailures = 0;

    /** How many times multi() was called */
    public int $multis = 0;

    /** How many of the next round trips lose the connection before the server read them */
    public int $resets = 0;

    /** How many round trips reached the server */
    public int $roundTrips = 0;

    /** How many times close() closed an open socket */
    public int $closes = 0;

    /** How many times close() was called on a closed one, which reconnects phpredis first */
    public int $closedWhileClosed = 0;

    private bool $connected = true;

    /** @var list<mixed> replies on the socket, oldest first */
    private array $wire = [];

    /** @var list<array{string, \Closure(): mixed}>|null the commands of an open pipeline */
    private ?array $queued = null;

    private ?string $lastError = null;

    /** @var array<string, string> the scripts the server holds, by sha1 */
    private array $scripts = [];

    public function __construct()
    {
        // no socket of phpredis: everything below answers from memory
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function close(): bool
    {
        if (!$this->connected) {
            ++$this->closedWhileClosed;

            return true;
        }

        ++$this->closes;
        $this->connected = false;
        $this->wire = [];

        return true;
    }

    public function multi(int $value = \Redis::MULTI): \Redis
    {
        ++$this->multis;

        if (0 < $this->multiFailures) {
            --$this->multiFailures;

            throw new \RedisException('Redis server vcfix2-redis:6379 went away');
        }

        $this->queued = [];

        return $this;
    }

    public function getMode(): int
    {
        return null === $this->queued ? \Redis::ATOMIC : \Redis::PIPELINE;
    }

    public function discard(): \Redis|bool
    {
        $this->queued = null;

        return true;
    }

    /**
     * @return list<mixed>|false
     */
    public function exec(): array|false
    {
        $commands = $this->queued ?? [];
        $this->queued = null;

        if (0 < $this->execsAnswerFalse) {
            --$this->execsAnswerFalse;
            $this->connected = true;
            ++$this->roundTrips;

            foreach ($commands as [, $run]) {
                $this->wire[] = $run();
            }

            return false;
        }

        return $this->roundTrip($commands);
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function clearLastError(): bool
    {
        $this->lastError = null;

        return true;
    }

    public function getOption(int $option): mixed
    {
        return \Redis::OPT_READ_TIMEOUT === $option ? $this->readTimeout : null;
    }

    public function _prefix(string $key): string
    {
        return $key;
    }

    /**
     * @param array<mixed> $fields
     *
     * @return \Redis|array<mixed>|false
     */
    public function hMget(string $key, array $fields): \Redis|array|false
    {
        $answer = $this->serve('HMGET', fn (): array => $this->row($key, $fields));

        return \is_array($answer) || $answer instanceof \Redis ? $answer : false;
    }

    /**
     * @param array<mixed> $args
     */
    public function evalsha(string $sha1, array $args = [], int $num_keys = 0): mixed
    {
        return $this->serve('EVALSHA', fn (): mixed => isset($this->scripts[$sha1]) ? $this->evaluate($this->scripts[$sha1], $args) : false);
    }

    /**
     * @param array<mixed> $args
     */
    public function eval(string $script, array $args = [], int $num_keys = 0): mixed
    {
        return $this->serve('EVAL', function () use ($script, $args): mixed {
            $this->scripts[sha1($script)] = $script;

            return $this->evaluate($script, $args);
        });
    }

    public function rawcommand(string $command, mixed ...$args): mixed
    {
        $strings = array_values(array_map(static fn (mixed $arg): string => \is_scalar($arg) ? (string) $arg : '', $args));

        return $this->serve($command, fn (): mixed => $this->raw($command, $strings));
    }

    /**
     * @return \Redis|array<mixed>|bool
     */
    public function xrange(string $key, string $start, string $end, int $count = -1): \Redis|array|bool
    {
        $answer = $this->serve('XRANGE', fn (): array => $this->range($start, $count, false));

        return \is_array($answer) || $answer instanceof \Redis ? $answer : false;
    }

    /**
     * @param array<mixed>|string $key
     */
    public function unlink(array|string $key, string ...$other_keys): \Redis|int|false
    {
        $answer = $this->serve('UNLINK', fn (): int => $this->forget($key, ...$other_keys));

        return \is_int($answer) || $answer instanceof \Redis ? $answer : false;
    }

    public function type(string $key): \Redis|int|false
    {
        $answer = $this->serve('TYPE', fn (): int => isset($this->items[$key]) ? \Redis::REDIS_HASH : \Redis::REDIS_NOT_FOUND);

        return \is_int($answer) || $answer instanceof \Redis ? $answer : false;
    }

    /**
     * @return \Redis|array<mixed>
     */
    public function time(): \Redis|array
    {
        $answer = $this->serve('TIME', static fn (): array => [(string) time(), '0']);

        return \is_array($answer) || $answer instanceof \Redis ? $answer : [];
    }

    /**
     * @param-out int $iterator
     *
     * @return array<mixed>|false
     */
    public function scan(int|string|null &$iterator, ?string $pattern = null, int $count = 0, ?string $type = null): array|false
    {
        // two pages: every key on the first, nothing on the second
        $first = \in_array($iterator, [null, 0, '0'], true);
        $iterator = $first ? 1 : 0;
        $answer = $this->serve('SCAN', fn (): array => $first ? array_values(array_filter(
            array_map(strval(...), array_keys($this->items)),
            static fn (string $key): bool => null === $pattern || fnmatch($pattern, $key),
        )) : []);

        return \is_array($answer) ? $answer : false;
    }

    /**
     * A command at once, or queued in an open pipeline.
     *
     * @param \Closure(): mixed $run what the server does and answers
     */
    private function serve(string $name, \Closure $run): mixed
    {
        if (null !== $this->queued) {
            $this->queued[] = [$name, $run];

            return $this;
        }

        return $this->roundTrip([[$name, $run]])[0] ?? false;
    }

    /**
     * Sends commands and reads as many replies off the socket: the oldest
     * there, which are a stalled round trip's where one was left.
     *
     * @param list<array{string, \Closure(): mixed}> $commands
     *
     * @return list<mixed>
     */
    private function roundTrip(array $commands): array
    {
        // a closed socket is opened again for the next command
        $this->connected = true;
        ++$this->roundTrips;

        // the server refuses, and the reply is read to its end
        if (null !== $error = $this->errorReply) {
            $this->errorReply = null;
            $this->lastError = $error;

            throw new \RedisException($error);
        }

        // an error reply read after another failure: phpredis throws it with
        // that failure as its previous exception, a reply still unread
        if (null !== $error = $this->errorAfterFailure) {
            $this->errorAfterFailure = null;

            foreach ($commands as [, $run]) {
                $this->wire[] = $run();
            }

            $this->lastError = $error;

            throw new \RedisException($error, 0, new \RedisException('socket error on read socket'));
        }

        // the connection is lost before the server read the commands
        if (0 < $this->resets) {
            --$this->resets;
            $this->connected = false;
            $this->wire = [];

            throw new \RedisException('Connection lost');
        }

        $stalls = 0 < $this->stalls && (null === $this->stallOn || \in_array($this->stallOn, array_column($commands, 0), true));

        if ($stalls && 0 < $this->stallsAfter) {
            --$this->stallsAfter;
            $stalls = false;
        }

        foreach ($commands as [, $run]) {
            $this->wire[] = $run();
        }

        if ($stalls) {
            --$this->stalls;
            usleep($this->stallMs * 1000);

            if ($this->closesOnStall) {
                $this->connected = false;
                $this->wire = [];
            }

            if (null !== $error = $this->errorAfterStall) {
                $this->errorAfterStall = null;
                $this->lastError = $error;

                throw new \RedisException($error);
            }

            throw new \RedisException('socket error on read socket');
        }

        return array_splice($this->wire, 0, \count($commands));
    }

    /**
     * The raw commands the adapter sends to a single node.
     *
     * @param list<string> $args
     */
    private function raw(string $command, array $args): mixed
    {
        $key = $args[0] ?? '';

        switch ($command) {
            case 'HSETEX':
                // KEY PX <ms> FIELDS <n> field value ...
                for ($i = 5; $i + 1 < \count($args); $i += 2) {
                    $this->items[$key][$args[$i]] = $args[$i + 1];
                }

                return 1;
            case 'HDEL':
                $removed = 0;

                foreach (\array_slice($args, 1) as $field) {
                    if (isset($this->items[$key][$field])) {
                        unset($this->items[$key][$field]);
                        ++$removed;
                    }
                }

                return $removed;
            case 'PEXPIRE':
                return isset($this->items[$key]) ? 1 : 0;
            case 'HPEXPIRE':
                return [1];
            default:
                return false;
        }
    }
}
