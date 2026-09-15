<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Benchmarks;

/**
 * One measured scenario.
 *
 * Wall clock is only as meaningful as the link it was measured over, so every
 * result also carries the two numbers that are not: how many commands Redis
 * executed, and how many socket reads it did to receive them. The second is
 * round trips in all but name - a pipelined batch arrives in one read - and
 * neither moves when the network does.
 */
final class Result
{
    public function __construct(
        public readonly string $store,
        public readonly string $scenario,
        public readonly float $seconds,
        public readonly int $commands,
        public readonly int $reads,
        public readonly ?string $note = null,
    ) {
    }
}

/**
 * Reads Redis' own counters around a piece of work.
 *
 * The counters are server-wide, so this only tells the truth on a Redis no one
 * else is talking to; bench.php refuses to start otherwise.
 */
final class Meter
{
    /** @var list<Result> */
    private array $results = [];

    public function __construct(private readonly \Redis|\Relay\Relay $probe)
    {
    }

    public function measure(string $store, string $scenario, \Closure $work, ?string $note = null): Result
    {
        [$commandsBefore, $readsBefore] = $this->counters();

        $began = microtime(true);
        $work();
        $seconds = microtime(true) - $began;

        [$commandsAfter, $readsAfter] = $this->counters();

        $result = new Result(
            $store,
            $scenario,
            $seconds,
            // the closing INFO is one command and one read of our own
            max(0, $commandsAfter - $commandsBefore - 1),
            max(0, $readsAfter - $readsBefore - 1),
            $note,
        );

        $this->results[] = $result;

        return $result;
    }

    public function note(string $store, string $scenario, string $note): void
    {
        $this->results[] = new Result($store, $scenario, 0.0, 0, 0, $note);
    }

    /**
     * @return list<Result>
     */
    public function results(): array
    {
        return $this->results;
    }

    public function usedMemory(): int
    {
        $memory = $this->probe->info('memory');

        return \is_array($memory) ? (int) ($memory['used_memory'] ?? 0) : 0;
    }

    /**
     * @return array{int, int}
     */
    private function counters(): array
    {
        $stats = $this->probe->info('stats');

        if (!\is_array($stats)) {
            return [0, 0];
        }

        return [
            (int) ($stats['total_commands_processed'] ?? 0),
            (int) ($stats['total_reads_processed'] ?? 0),
        ];
    }
}
