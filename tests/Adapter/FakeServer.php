<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests\Adapter;

use Artigo\Cache\Adapter\InvalidationRules;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;

/**
 * A rules stream and a few items held in memory, for the connections the
 * replica and pipeline tests stand in for. The primary holds every entry; a
 * replica holds the first $replicaHolds of them, or all when it is null.
 */
trait FakeServer
{
    /** @var array<string, array<string, string>> id => fields, oldest first */
    public array $stream = [];

    public ?int $replicaHolds = null;

    /** How many ranges were asked of the primary through EVAL */
    public int $primaryReads = 0;

    /** @var array<string, array<string, string>> */
    public array $items = [];

    /** @var list<string> */
    public array $unlinked = [];

    private int $clock = 1_000;

    /**
     * Appends a rule for one tag straight into the stream, as another server
     * would, and answers its id.
     */
    public function addRule(string $tag, bool $opened = false): string
    {
        $id = ($this->clock++).'-0';
        $this->stream[$id] = ['t' => json_encode(['t' => [$tag]], \JSON_THROW_ON_ERROR), 'first' => $opened ? '1' : '0'];

        return $id;
    }

    /**
     * An item as the adapter writes it: the marshalled value, its tags and its
     * watermark.
     *
     * @param list<string> $tags
     */
    public function storeItem(string $key, string $value, array $tags, string $mark): void
    {
        $marshalled = (new DefaultMarshaller())->marshall(['k' => ['value' => $value]], $failed);

        $this->items[$key] = [
            'v' => \is_string($marshalled['k'] ?? null) ? $marshalled['k'] : '',
            't' => json_encode($tags, \JSON_THROW_ON_ERROR),
            'm' => $mark,
        ];
    }

    /**
     * XRANGE from $start, inclusively, up to $count entries: of the primary,
     * or of the replica.
     *
     * @return array<string, array<string, string>>
     */
    private function range(string $start, int $count, bool $primary): array
    {
        $entries = $primary || null === $this->replicaHolds ? $this->stream : \array_slice($this->stream, 0, $this->replicaHolds, true);
        $range = [];

        foreach ($entries as $id => $fields) {
            if ('-' !== $start && InvalidationRules::isNewerId($start, $id)) {
                continue;
            }

            $range[$id] = $fields;

            if ($count > 0 && \count($range) >= $count) {
                break;
            }
        }

        return $range;
    }

    /**
     * The scripts the adapter sends, played against the stream and the items.
     *
     * @param array<mixed> $args
     */
    private function evaluate(string $script, array $args): mixed
    {
        $rule = \is_string($args[1] ?? null) ? $args[1] : '{}';

        // the guarded write: lands where the epoch is the one the read saw
        if (str_contains($script, "'HGET', KEYS[1], 'e'")) {
            $key = \is_string($args[0] ?? null) ? $args[0] : '';

            if (($this->items[$key]['e'] ?? '') !== ($args[4] ?? null)) {
                return 0;
            }

            $this->items[$key] = [
                'v' => \is_string($args[1] ?? null) ? $args[1] : '',
                't' => \is_string($args[2] ?? null) ? $args[2] : '[]',
                'm' => \is_string($args[3] ?? null) ? $args[3] : InvalidationRules::NONE,
                'e' => \is_string($args[5] ?? null) ? $args[5] : '',
            ];

            return 1;
        }

        if (str_contains($script, 'XADD')) {
            $previous = array_key_last($this->stream) ?? InvalidationRules::NONE;
            $opened = [] === $this->stream;
            $id = ($this->clock++).'-0';
            $this->stream[$id] = ['t' => $rule, 'first' => $opened ? '1' : '0'];

            return [$id, $previous, $opened ? '1' : '0'];
        }

        ++$this->primaryReads;
        $reply = [];

        foreach ($this->range($rule, \is_scalar($args[2] ?? null) ? (int) $args[2] : -1, true) as $id => $fields) {
            $flat = [];

            foreach ($fields as $field => $value) {
                $flat[] = $field;
                $flat[] = $value;
            }

            $reply[] = [$id, $flat];
        }

        return $reply;
    }

    /**
     * HMGET of an item: each field asked for, false where there is none.
     *
     * @param array<mixed> $fields
     *
     * @return array<string, string|false>
     */
    private function row(string $key, array $fields): array
    {
        $row = [];

        foreach ($fields as $field) {
            if (\is_string($field)) {
                $row[$field] = $this->items[$key][$field] ?? false;
            }
        }

        return $row;
    }

    /**
     * @param array<mixed>|string $key
     */
    private function forget(array|string $key, string ...$others): int
    {
        foreach ([...(array) $key, ...$others] as $one) {
            if (\is_string($one)) {
                unset($this->items[$one]);
                $this->unlinked[] = $one;
            }
        }

        return \count($others) + \count((array) $key);
    }
}
