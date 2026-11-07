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
 * A pool that keeps, for a while, a value a soft invalidation made outdated.
 * A read still misses it, and a consumer that may serve it stale, such as
 * artigo/cache-stampede's StaleCache in soft mode, takes it from here.
 *
 * @experimental the name and the shape may change before a pool outside this
 *               package implements it
 */
interface AgedItemsInterface
{
    /**
     * The value the last read of $key kept because only soft rules had made
     * it outdated, handed out once: the value, its tags, and how many
     * milliseconds ago the newest of those rules was added. Null when there
     * is none.
     *
     * A kept value is dropped by the next read, write or delete of $key
     * through this pool, and every kept value by a clear or a hard
     * invalidation through it, or by a reset. A read keeps a new one only when
     * it finds the value made outdated by soft rules alone, within the grace:
     * a tombstone, a hard rule or a clear leaves nothing to hand out. A soft
     * invalidation through this pool, and any change made through another pool
     * or process, leaves a value already kept as it is, until one of the
     * above drops it.
     *
     * @return array{value: mixed, tags: list<string>, ageMs: int}|null
     */
    public function takeAgedItem(string $key): ?array;
}
