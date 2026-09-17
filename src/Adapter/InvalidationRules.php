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
 * The invalidation rules one process knows about, kept in the shape a read
 * needs rather than the shape the stream has.
 *
 * A rule invalidates an item when it is newer than the item's watermark and
 * names one of the item's tags, or a prefix of its id. Only the newest rule
 * per tag can decide that: if it is not newer than the watermark, no older
 * rule for the same tag is either. So that is all that is kept, and a read
 * costs the item's tag count whatever the length of the log.
 *
 * The set follows the server's stream incrementally. Each refresh asks for
 * the entries from the last id it holds, inclusively. If that id comes back
 * first, everything after it is new. If it does not, the server no longer
 * holds it - the stream was trimmed past it, cleared to a newer rule, or
 * deleted - and what came back is the whole current stream, which replaces
 * what was held. Either way one round trip, sized by what changed.
 *
 * It also notices when the stream has lost rules. Every entry says whether it
 * opened the stream (the adapter's script records it). An item stamped with a
 * real id has seen the stream hold at least that entry, so if nothing is held
 * now, or the oldest entry held opened the stream and is newer than the
 * stamp, whatever stood between is gone - evicted, deleted, or lost with a
 * slot - and may have invalidated the item. The item is stale then, whatever
 * its tags: the one verdict that cannot serve a value past its invalidation.
 * An item stamped 0-0 saw no rule and is exempt.
 *
 * @internal
 */
final class InvalidationRules
{
    /**
     * The id of a stream that has no entries yet. Every real id sorts above
     * it, so an item stamped with it has seen nothing.
     */
    public const NONE = '0-0';

    private const FIELD_RULE = 't';
    private const FIELD_FIRST = 'first';

    /**
     * The newest rule id naming each tag.
     *
     * @var array<string, array{int, int}>
     */
    private array $tags = [];

    /**
     * The prefix rules, oldest first.
     *
     * @var list<array{int, int, string}>
     */
    private array $prefixes = [];

    private string $last = self::NONE;

    /**
     * The oldest id received from the server, and whether that entry opened
     * the stream - null when it did not say, having been written before the
     * adapter recorded it.
     */
    private string $first = self::NONE;
    private ?bool $firstOpened = null;

    /**
     * @param string $poolNamespace the prefix every id in the pool carries; a prefix rule equal to it covers the whole pool
     * @param int    $retentionMs   how long a rule can matter, being the longest an item may live
     */
    public function __construct(
        private readonly string $poolNamespace,
        private readonly int $retentionMs,
    ) {
    }

    /**
     * The newest id received from the server: where the next fetch starts,
     * and the watermark a write may carry.
     */
    public function last(): string
    {
        return $this->last;
    }

    /**
     * The shape that crosses requests (see SharedRules): plain arrays,
     * nothing else, so any cache pool stores it as it is.
     *
     * @return array{tags: array<string, array{int, int}>, prefixes: list<array{int, int, string}>, last: string, first: string, first_opened: bool|null}
     */
    public function toArray(): array
    {
        return [
            'tags' => $this->tags,
            'prefixes' => $this->prefixes,
            'last' => $this->last,
            'first' => $this->first,
            'first_opened' => $this->firstOpened,
        ];
    }

    /**
     * Rebuilds a set from toArray(); null when the shape is not one we wrote.
     */
    public static function fromArray(mixed $state, string $poolNamespace, int $retentionMs): ?self
    {
        if (!\is_array($state)
            || !\is_array($state['tags'] ?? null)
            || !\is_array($state['prefixes'] ?? null)
            || !\is_string($state['last'] ?? null)
            || !\is_string($state['first'] ?? null)
        ) {
            return null;
        }

        $tags = [];

        foreach ($state['tags'] as $tag => $rule) {
            if (!\is_array($rule) || !\is_int($rule[0] ?? null) || !\is_int($rule[1] ?? null)) {
                return null;
            }

            // a numeric tag came back as an int key, as PHP has it
            $tags[(string) $tag] = [$rule[0], $rule[1]];
        }

        $prefixes = [];

        foreach ($state['prefixes'] as $rule) {
            if (!\is_array($rule) || !\is_int($rule[0] ?? null) || !\is_int($rule[1] ?? null) || !\is_string($rule[2] ?? null)) {
                return null;
            }

            $prefixes[] = [$rule[0], $rule[1], $rule[2]];
        }

        $firstOpened = $state['first_opened'] ?? null;

        $rules = new self($poolNamespace, $retentionMs);
        $rules->tags = $tags;
        $rules->prefixes = $prefixes;
        $rules->last = $state['last'];
        $rules->first = $state['first'];
        $rules->firstOpened = \is_bool($firstOpened) ? $firstOpened : null;

        return $rules;
    }

    /**
     * Is the first id newer than the second?
     */
    public static function isNewerId(string $id, string $than): bool
    {
        [$ms, $sequence] = self::split($id);
        [$thanMs, $thanSequence] = self::split($than);

        return $ms > $thanMs || ($ms === $thanMs && $sequence > $thanSequence);
    }

    /**
     * Merges the entries of an XRANGE that started at last(), inclusively -
     * or at the beginning, when nothing was held yet.
     *
     * @param array<array-key, mixed> $entries id => fields, oldest first, as the client hands them back
     */
    public function absorb(array $entries): void
    {
        if (self::NONE !== $this->last) {
            $start = array_key_first($entries);

            if (null !== $start && (string) $start === $this->last) {
                unset($entries[$start]);
            } else {
                // the stream moved from under us: whatever it holds now is
                // the whole truth, and what we held is not part of it
                $this->tags = [];
                $this->prefixes = [];
                $this->last = self::NONE;
                $this->first = self::NONE;
                $this->firstOpened = null;
            }
        }

        foreach ($entries as $id => $entry) {
            $this->add((string) $id, $entry);
        }

        $this->trim();
    }

    /**
     * Has any rule the item has not seen invalidated it?
     *
     * @param list<string> $tags
     */
    public function invalidates(string $id, array $tags, string $mark): bool
    {
        if ($this->lostSince($mark)) {
            return true;
        }

        [$ms, $sequence] = self::split($mark);

        foreach ($tags as $tag) {
            if (isset($this->tags[$tag]) && self::isNewer($this->tags[$tag], $ms, $sequence)) {
                return true;
            }
        }

        foreach ($this->prefixes as [$ruleMs, $ruleSequence, $prefix]) {
            if (self::isNewer([$ruleMs, $ruleSequence], $ms, $sequence) && str_starts_with($id, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Has the stream lost rules the item has seen?
     *
     * An item stamped with a real id has seen the stream hold at least that
     * entry. If nothing is held now, or the oldest entry held opened the
     * stream and is newer than the stamp, whatever stood between is gone and
     * may have invalidated the item. An item stamped 0-0 saw no rule, so
     * nothing lost can concern it - and the first rule a pool ever gets opens
     * the stream without being a rebuild, which is why the exemption is exact.
     *
     * The comparison is an ordering, so it cannot see a rebuild that opened
     * on an id the item already carries: ids come from the server clock, and
     * a stream lost and reborn inside the millisecond its items were stamped
     * in starts again at that same id. An item stamped on the opening rule of
     * a stream that still holds it is the common case and must stay fresh, so
     * equality cannot be read as loss. Telling the two apart needs identity
     * rather than order - a token on the opening rule, carried by the stamp.
     */
    private function lostSince(string $mark): bool
    {
        if (self::NONE === $mark) {
            return false;
        }

        if (self::NONE === $this->last) {
            return true;
        }

        if (true !== $this->firstOpened) {
            return false;
        }

        [$ms, $sequence] = self::split($mark);

        return self::isNewer(self::split($this->first), $ms, $sequence);
    }

    /**
     * @return array{int, int}
     */
    public static function split(string $id): array
    {
        $parts = explode('-', $id, 2);

        return [(int) $parts[0], (int) ($parts[1] ?? 0)];
    }

    /**
     * The older of two stream ids.
     */
    public static function older(string $a, string $b): string
    {
        [$aMs, $aSequence] = self::split($a);
        [$bMs, $bSequence] = self::split($b);

        return $aMs < $bMs || ($aMs === $bMs && $aSequence <= $bSequence) ? $a : $b;
    }

    /**
     * Redis hands back whatever was written, and JSON decodes to anything at
     * all: this is where both become the list of strings we expect.
     *
     * @return list<string>
     */
    public static function strings(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $strings = [];

        foreach ($value as $item) {
            if (\is_string($item)) {
                $strings[] = $item;
            } elseif (\is_scalar($item)) {
                $strings[] = (string) $item;
            }
        }

        return $strings;
    }

    private function add(string $id, mixed $entry): void
    {
        [$ms, $sequence] = self::split($id);
        $encoded = \is_array($entry) ? $entry[self::FIELD_RULE] ?? '{}' : '{}';
        $rule = json_decode(\is_string($encoded) ? $encoded : '{}', true);
        $rule = \is_array($rule) ? $rule : [];

        // entries arrive oldest first, so the last write for a tag is the newest rule
        foreach (self::strings($rule['t'] ?? null) as $tag) {
            $this->tags[$tag] = [$ms, $sequence];
        }

        if (\is_string($prefix = $rule['p'] ?? null)) {
            $this->prefixes[] = [$ms, $sequence, $prefix];
        }

        if (self::NONE === $this->first) {
            $opened = \is_array($entry) ? $entry[self::FIELD_FIRST] ?? null : null;

            $this->first = $id;
            $this->firstOpened = \is_scalar($opened) ? '1' === (string) $opened : null;
        }

        $this->last = $id;
    }

    /**
     * Drops what can no longer decide anything: rules older than the longest
     * possible item lifetime, having nothing left alive to match, and rules
     * older than one covering the whole pool, which says everything they
     * could say.
     */
    private function trim(): void
    {
        if (self::NONE === $this->last) {
            return;
        }

        [$lastMs] = self::split($this->last);
        $floor = [$lastMs - $this->retentionMs, 0];

        foreach ($this->prefixes as [$ms, $sequence, $prefix]) {
            if ($prefix === $this->poolNamespace && self::isNewer([$ms, $sequence], $floor[0], $floor[1])) {
                $floor = [$ms, $sequence];
            }
        }

        foreach ($this->tags as $tag => [$ms, $sequence]) {
            if (!self::isNewer([$ms, $sequence], $floor[0], $floor[1])) {
                unset($this->tags[$tag]);
            }
        }

        $kept = [];

        foreach ($this->prefixes as $rule) {
            // the rule that set the floor stays: it is the one still matching
            if ($rule[0] === $floor[0] && $rule[1] === $floor[1] || self::isNewer($rule, $floor[0], $floor[1])) {
                $kept[] = $rule;
            }
        }

        $this->prefixes = $kept;
    }

    /**
     * @param array{0: int, 1: int, 2?: string} $rule
     */
    private static function isNewer(array $rule, int $ms, int $sequence): bool
    {
        return $rule[0] > $ms || ($rule[0] === $ms && $rule[1] > $sequence);
    }
}
