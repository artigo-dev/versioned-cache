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
 * What a pool remembers of the reads that a write may follow. Per id: the
 * watermark the write will carry (the last rule id of the copy of the rules
 * the read used), the epoch the item carried (empty when it had none), and
 * when the read was made. The write that follows is stored only if the
 * item's epoch is still the same. It carries the read's watermark rather
 * than the newest id in the stream, so a rule appended meanwhile still makes
 * it outdated.
 *
 * The map is kept in the order the reads were made. Once it holds more than
 * CAP records besides the largest single fetch, the oldest go first. So a
 * process that reads and never writes holds a map of limited size, and the
 * reads of one batch are never forgotten before its write.
 *
 * @internal
 */
final class MissRecords
{
    /**
     * How many reads a pool remembers besides its largest single fetch. The
     * oldest are forgotten first, and a write with no remembered read is a
     * plain write.
     */
    public const CAP = 1_000;

    /**
     * @var array<string, array{string, string, float}> id => [watermark, epoch, read at in milliseconds]
     */
    private array $records = [];

    private int $cap = self::CAP;

    /**
     * Makes room for a fetch of $count ids at once, on top of CAP, until
     * clear(). The caller holds what it fetched in one call in memory anyway,
     * and every one of those reads may be followed by a write.
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
     * @return array{string, string, float}|null [watermark, epoch, read at in milliseconds]
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
     * Gives the reads whose watermark is $previous the watermark $id instead:
     * $id is the rule this pool appended right after $previous. The write that
     * follows such a read was computed by the pool that made the rule, so
     * that rule must not make it outdated. A read with an older watermark did
     * not see the rules in between, which may come from elsewhere, so it keeps
     * its watermark. The epochs stay as they are, so a delete from elsewhere
     * still refuses the write.
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
