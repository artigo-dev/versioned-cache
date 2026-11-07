<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Adapter;

/**
 * What a pool remembers of the reads that will be followed by a write: per
 * id, the rule id the read was judged against (its mark), the epoch the item
 * carried (empty when there was none) and when the read was taken. The write
 * that follows lands only where the epoch is still the same, and carries the
 * read's mark rather than the stream's head, so a rule appended meanwhile
 * still judges it.
 *
 * Insertion order is age: the oldest record goes first once there are more
 * than CAP besides the largest single read, so a process that reads and never
 * writes holds a bounded map, and a batch is never cut short before its write.
 *
 * @internal
 */
final class MissRecords
{
    /**
     * How many reads a pool remembers besides its largest single read; the
     * oldest are forgotten first, and a write with no record is a plain one.
     */
    public const CAP = 1_000;

    /**
     * @var array<string, array{string, string, float}> id => [mark, epoch, taken at in milliseconds]
     */
    private array $records = [];

    private int $cap = self::CAP;

    /**
     * Makes room for a read of $count ids at once on top of CAP, until
     * clear(): what the caller fetched in one call is held in its memory
     * anyway, and every one of those reads may be followed by a write.
     */
    public function reserve(int $count): void
    {
        $this->cap = max($this->cap, self::CAP + $count);
    }

    /**
     * Remembers a read of $id, replacing an older one and making it the
     * newest.
     */
    public function take(string $id, string $mark, string $epoch): void
    {
        unset($this->records[$id]);
        $this->records[$id] = [$mark, $epoch, microtime(true) * 1000];

        if ($this->cap < \count($this->records)) {
            unset($this->records[array_key_first($this->records)]);
        }
    }

    /**
     * @return array{string, string, float}|null [mark, epoch, taken at in milliseconds]
     */
    public function of(string $id): ?array
    {
        return $this->records[$id] ?? null;
    }

    public function drop(string ...$ids): void
    {
        foreach ($ids as $id) {
            unset($this->records[$id]);
        }
    }

    public function clear(): void
    {
        $this->records = [];
        $this->cap = self::CAP;
    }

    /**
     * Moves the marks of the reads that had seen the stream up to $previous
     * on to $id, the rule this pool appended right after it: the write that
     * follows was computed by the pool that made the rule, and must not be
     * judged stale by it. A read whose mark is older did not see what stands
     * between, and keeps its mark. The epochs stay as they are, so a delete
     * from elsewhere still refuses the write.
     */
    public function restamp(string $previous, string $id): void
    {
        foreach ($this->records as $recordId => [$mark, $epoch, $atMs]) {
            if ($mark === $previous) {
                $this->records[$recordId] = [$id, $epoch, $atMs];
            }
        }
    }

    public function count(): int
    {
        return \count($this->records);
    }
}
