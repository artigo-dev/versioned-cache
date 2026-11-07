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
 * A rule makes an item outdated when it is newer than the item's watermark
 * and names one of the item's tags, or a prefix of its id. Only the newest
 * rule per tag can decide that: if it is not newer than the watermark, no
 * older rule for the same tag is either. So that is all that is kept, and a
 * read costs the item's tag count, whatever the length of the log.
 *
 * The set follows the server's stream step by step. Each refresh asks for
 * the entries from the last id it holds, that id included. If that id comes
 * back first, everything after it is new. If it does not, the server no
 * longer holds it (the stream was trimmed past it, cleared to a newer rule,
 * or deleted), and what came back is the whole current stream, which
 * replaces what was held. Either way it is one round trip, sized by what
 * changed.
 *
 * It also notices when the stream has lost rules. Every entry says whether it
 * started the stream (the adapter's script records that). An item whose
 * watermark is a real id has seen the stream hold at least that entry. So if
 * nothing is held now, or the oldest entry held started the stream and is
 * newer than the watermark, whatever stood between is gone (evicted, deleted,
 * or lost with a slot) and may have invalidated the item. The item is then
 * outdated, whatever its tags: the one answer that cannot serve a value past
 * its invalidation. An item whose watermark is 0-0 saw no rule and is exempt.
 *
 * The set has a limit. Past $maxTags rules, tags and prefixes together, it
 * keeps its newest 90 % and puts a floor in place of the rest: a rule for the
 * whole pool at the newest id it dropped. Every dropped rule is at or below
 * the floor, so whatever one of them invalidated, the floor invalidates too.
 * An item with a watermark before the floor reads as a miss, never as an
 * outdated hit, and one at or after it is checked against the other rules as
 * before. The 10 % of headroom keeps a set at its limit from being cut again
 * by every rule that follows.
 *
 * A tag rule may be hard. Beside the newest rule per tag, the set keeps the
 * newest hard one, so that invalidatedAt() can tell an item a soft rule
 * reached, which a reader may keep for a grace, from one that a hard rule, a
 * clear, a floor or a lost stream reached, which it may not.
 *
 * Rule ids are held as one integer each: the milliseconds above 20 bits of
 * sequence number. So checking an item is an integer comparison, and the set
 * crosses a request as a flat array. 20 bits leave 43 for the milliseconds,
 * enough until the year 2248. A sequence number too large for its bits can
 * only make an item outdated, never fresh: such a rule looks newer than it
 * is, and such a watermark older (see rule() and mark()).
 *
 * @internal
 */
final class InvalidationRules
{
    /**
     * The id of a stream that has no entries yet. Every real id sorts above
     * it, so an item with it as its watermark has seen no rule.
     */
    public const NONE = '0-0';

    private const FIELD_RULE = 't';
    private const FIELD_FIRST = 'first';

    /**
     * Set in a rule to make it hard: see invalidatedAt().
     */
    public const HARD = 'h';

    private const SEQUENCE_BITS = 20;
    private const SEQUENCE_MAX = (1 << self::SEQUENCE_BITS) - 1;

    /**
     * The newest rule id naming each tag, packed (see rule()).
     *
     * @var array<string, int>
     */
    private array $tags = [];

    /**
     * The newest hard rule id naming each tag, packed.
     *
     * @var array<string, int>
     */
    private array $hard = [];

    /**
     * The prefix rules, oldest first: packed id, prefix.
     *
     * @var list<array{int, string}>
     */
    private array $prefixes = [];

    private string $last = self::NONE;

    /**
     * The oldest id received from the server, and whether that entry started
     * the stream. Null when the entry does not say, because it was written
     * without that field.
     */
    private string $first = self::NONE;
    private ?bool $firstOpened = null;

    /**
     * @param string $poolNamespace the prefix every id in the pool carries; a prefix rule equal to it covers the whole pool
     * @param int    $retentionMs   how long a rule can matter, being the longest an item may live
     * @param int    $maxTags       how many rules the set holds before one floor rule replaces the oldest; 0 for no limit
     */
    public function __construct(
        private readonly string $poolNamespace,
        private readonly int $retentionMs,
        private readonly int $maxTags = 0,
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
     * @return array{tags: array<string, int>, hard: array<string, int>, prefixes: list<array{int, string}>, last: string, first: string, first_opened: bool|null}
     */
    public function toArray(): array
    {
        return [
            'tags' => $this->tags,
            'hard' => $this->hard,
            'prefixes' => $this->prefixes,
            'last' => $this->last,
            'first' => $this->first,
            'first_opened' => $this->firstOpened,
        ];
    }

    /**
     * Rebuilds a set from toArray(), checking every rule; null when the shape
     * is not one we wrote. For a store others can write to.
     */
    public static function fromArray(mixed $state, string $poolNamespace, int $retentionMs, int $maxTags = 0): ?self
    {
        if (!\is_array($state) || !\is_array($state['tags'] ?? null) || !\is_array($state['hard'] ?? null) || !\is_array($state['prefixes'] ?? null)) {
            return null;
        }

        $tags = self::packed($state['tags']);
        $hard = self::packed($state['hard']);

        if (null === $tags || null === $hard) {
            return null;
        }

        $prefixes = [];

        foreach ($state['prefixes'] as $rule) {
            if (!\is_array($rule) || !\is_int($rule[0] ?? null) || !\is_string($rule[1] ?? null)) {
                return null;
            }

            $prefixes[] = [$rule[0], $rule[1]];
        }

        return self::fromTrusted(['tags' => $tags, 'hard' => $hard, 'prefixes' => $prefixes] + $state, $poolNamespace, $retentionMs, $maxTags);
    }

    /**
     * Tag => packed rule id, as toArray() wrote it; null when it is not.
     *
     * @param array<mixed> $rules
     *
     * @return array<string, int>|null
     */
    private static function packed(array $rules): ?array
    {
        $packed = [];

        foreach ($rules as $tag => $rule) {
            if (!\is_int($rule)) {
                return null;
            }

            // PHP turns a numeric tag into an int key
            $packed[(string) $tag] = $rule;
        }

        return $packed;
    }

    /**
     * Rebuilds a set from toArray() without looking at each rule: for an entry
     * only this package writes, under a format it has checked (see
     * SharedRules). Null when the outer shape is not one we wrote.
     */
    public static function fromTrusted(mixed $state, string $poolNamespace, int $retentionMs, int $maxTags = 0): ?self
    {
        if (!\is_array($state)
            || !\is_array($state['tags'] ?? null)
            || !\is_array($state['hard'] ?? null)
            || !\is_array($state['prefixes'] ?? null)
            || !\is_string($state['last'] ?? null)
            || !\is_string($state['first'] ?? null)
        ) {
            return null;
        }

        $firstOpened = $state['first_opened'] ?? null;

        /** @var array<string, int> $tags the checked format guarantees each rule's shape */
        $tags = $state['tags'];
        /** @var array<string, int> $hard */
        $hard = $state['hard'];
        /** @var list<array{int, string}> $prefixes */
        $prefixes = $state['prefixes'];

        $rules = new self($poolNamespace, $retentionMs, $maxTags);
        $rules->tags = $tags;
        $rules->hard = $hard;
        $rules->prefixes = $prefixes;
        $rules->last = $state['last'];
        $rules->first = $state['first'];
        $rules->firstOpened = \is_bool($firstOpened) ? $firstOpened : null;

        return $rules;
    }

    /**
     * A rule's id as one integer that sorts the way the ids do. A sequence
     * number too large for its bits is cut to the largest that fits, which
     * mark() places above every watermark of that millisecond: the rule can
     * only look newer than it is.
     */
    public static function rule(string $id): int
    {
        [$ms, $sequence] = self::split($id);

        return $ms << self::SEQUENCE_BITS | min($sequence, self::SEQUENCE_MAX);
    }

    /**
     * An item's watermark as one integer. A sequence number too large for its
     * bits becomes 0, and so does the largest that fits, which is where rule()
     * puts an overflowing rule of the same millisecond. The item can only look
     * older than it is, so an overflow can make it outdated, but never serves
     * an outdated value.
     */
    public static function mark(string $id): int
    {
        [$ms, $sequence] = self::split($id);

        return $ms << self::SEQUENCE_BITS | ($sequence >= self::SEQUENCE_MAX ? 0 : $sequence);
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
     * Does an XRANGE that started at last() carry on from what is held? It
     * does when it comes back starting with that id, or when nothing was held.
     * Otherwise absorbing it rebuilds the set from this answer alone.
     *
     * @param array<array-key, mixed> $entries id => fields, oldest first
     */
    public function continuedBy(array $entries): bool
    {
        if (self::NONE === $this->last) {
            return true;
        }

        $start = array_key_first($entries);

        return null !== $start && (string) $start === $this->last;
    }

    /**
     * Merges the entries of an XRANGE that started at last(), that id
     * included, or at the beginning when nothing was held yet.
     *
     * @param array<array-key, mixed> $entries id => fields, oldest first, as the client hands them back
     */
    public function absorb(array $entries): void
    {
        if (self::NONE !== $this->last) {
            $start = array_key_first($entries);

            if (null !== $start && $this->continuedBy($entries)) {
                unset($entries[$start]);
            } else {
                // the stream no longer holds our last id: what came back is the
                // whole current stream, and what we held is dropped
                $this->tags = [];
                $this->hard = [];
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
     * Adds the rule the adapter just appended, from the id the server answered,
     * without reading the stream. That is only right for a rule appended
     * directly after last(), which the caller has checked. Unlike absorb(), it
     * never rebuilds the set.
     *
     * @param array{t?: list<string>, p?: string, h?: int} $rule
     * @param bool                                         $opened whether the rule opened the stream
     */
    public function append(string $id, array $rule, bool $opened = false): void
    {
        $this->addRule($id, $rule, $opened);

        // trimming walks every rule, too much work for every invalidation. A
        // tag rule only moves the retention cut-off by the time since the last
        // rule, and a rule past the retention matches no living item anyway,
        // so that trim waits for the next absorb(). A clear of the whole pool,
        // or a set past its limit, is trimmed now
        if (($rule['p'] ?? null) === $this->poolNamespace || ($this->maxTags > 0 && \count($this->tags) + \count($this->prefixes) > $this->maxTags)) {
            $this->trim();
        }
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

        $seen = self::mark($mark);

        foreach ($tags as $tag) {
            if (isset($this->tags[$tag]) && $this->tags[$tag] > $seen) {
                return true;
            }
        }

        foreach ($this->prefixes as [$rule, $prefix]) {
            if ($rule > $seen && str_starts_with($id, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * When the rules that make the item outdated were added, if all of them
     * are soft: the milliseconds of the newest rule it has not seen that names
     * one of its tags. Null when one of them is not soft (a hard rule it has
     * not seen names one of its tags, a prefix rule such as a clear or the
     * floor reaches it, or the stream lost rules it saw), and null when
     * nothing makes it outdated. The newest hard rule per tag is kept beside
     * the newest rule, so a soft rule after a hard one leaves the hard one
     * standing.
     *
     * @param list<string> $tags
     */
    public function invalidatedAt(string $id, array $tags, string $mark): ?int
    {
        if ($this->lostSince($mark)) {
            return null;
        }

        $seen = self::mark($mark);

        foreach ($this->prefixes as [$rule, $prefix]) {
            if ($rule > $seen && str_starts_with($id, $prefix)) {
                return null;
            }
        }

        $newest = 0;

        foreach ($tags as $tag) {
            if (($this->hard[$tag] ?? 0) > $seen) {
                return null;
            }

            $rule = $this->tags[$tag] ?? 0;

            if ($rule > $seen && $rule > $newest) {
                $newest = $rule;
            }
        }

        return 0 === $newest ? null : $newest >> self::SEQUENCE_BITS;
    }

    /**
     * Has the stream lost rules the item has seen?
     *
     * An item whose watermark is a real id has seen the stream hold at least
     * that entry. If nothing is held now, or the oldest entry held started the
     * stream and is newer than the watermark, whatever stood between is gone
     * and may have invalidated the item. An item whose watermark is 0-0 saw no
     * rule, so nothing lost can concern it. And the first rule a pool ever
     * gets starts the stream without being a rebuild, which is why the
     * exemption is exact.
     *
     * The comparison is an ordering, so it cannot see a rebuild that started
     * on an id the item already carries. Ids come from the server's clock, and
     * a stream lost and created again within the millisecond of the item's
     * watermark starts again at that same id. An item whose watermark is the
     * first rule of a stream that still holds it is the common case, and must
     * stay fresh, so equal ids cannot be read as a loss. Telling the two apart
     * would need identity rather than order: a token on the first rule,
     * carried in the item's watermark.
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

        return self::rule($this->first) > self::mark($mark);
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
        $encoded = \is_array($entry) ? $entry[self::FIELD_RULE] ?? '{}' : '{}';
        $rule = json_decode(\is_string($encoded) ? $encoded : '{}', true);
        $opened = \is_array($entry) ? $entry[self::FIELD_FIRST] ?? null : null;

        $this->addRule($id, \is_array($rule) ? $rule : [], \is_scalar($opened) ? '1' === (string) $opened : null);
    }

    /**
     * @param array<mixed> $rule
     */
    private function addRule(string $id, array $rule, ?bool $opened): void
    {
        $packed = self::rule($id);

        $hard = !empty($rule[self::HARD]);

        // entries arrive oldest first, so the last write for a tag is the newest rule
        foreach (self::strings($rule['t'] ?? null) as $tag) {
            $this->tags[$tag] = $packed;

            if ($hard) {
                $this->hard[$tag] = $packed;
            }
        }

        if (\is_string($prefix = $rule['p'] ?? null)) {
            $this->prefixes[] = [$packed, $prefix];
        }

        if (self::NONE === $this->first) {
            $this->first = $id;
            $this->firstOpened = $opened;
        }

        $this->last = $id;
    }

    /**
     * Drops the rules that can no longer change any answer.
     *
     * Rules older than the longest possible item lifetime go, because no
     * living item can match them. Rules older than one covering the whole
     * pool go, because that rule already says everything they could say. And
     * past the limit, the oldest rules go, with a floor in their place (see
     * cap()).
     */
    private function trim(): void
    {
        if (self::NONE === $this->last) {
            return;
        }

        $this->cap();

        [$lastMs] = self::split($this->last);
        $floor = ($lastMs - $this->retentionMs) << self::SEQUENCE_BITS;

        foreach ($this->prefixes as [$rule, $prefix]) {
            if ($prefix === $this->poolNamespace && $rule > $floor) {
                $floor = $rule;
            }
        }

        foreach ($this->tags as $tag => $rule) {
            if ($rule <= $floor) {
                unset($this->tags[$tag]);
            }
        }

        // a hard rule at or below the cut-off is not needed either: past the
        // retention it matches nothing, and a whole-pool rule is hard itself
        foreach ($this->hard as $tag => $rule) {
            if ($rule <= $floor) {
                unset($this->hard[$tag]);
            }
        }

        $kept = [];

        foreach ($this->prefixes as $rule) {
            // the whole-pool rule that set the cut-off stays: it is the one
            // that still matches
            if ($rule[0] >= $floor) {
                $kept[] = $rule;
            }
        }

        $this->prefixes = $kept;
    }

    /**
     * Past the limit, adds a rule for the whole pool at the newest id outside
     * the newest 90 % of the rules: the floor. The rest of trim() then drops
     * every rule the floor covers.
     */
    private function cap(): void
    {
        if (0 === $this->maxTags || \count($this->tags) + \count($this->prefixes) <= $this->maxTags) {
            return;
        }

        $ids = array_values($this->tags);

        foreach ($this->prefixes as [$rule]) {
            $ids[] = $rule;
        }

        // newest first
        rsort($ids);

        // the oldest prefix rule from here on, so the list stays oldest first
        array_unshift($this->prefixes, [$ids[intdiv($this->maxTags * 9, 10)], $this->poolNamespace]);
    }
}
