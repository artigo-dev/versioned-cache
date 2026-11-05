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
 * A pool that keeps, for a while, what a soft invalidation reached: a read
 * misses it all the same, and a consumer that may serve it stale - such as
 * artigo/cache-stampede's StaleCache in soft mode - takes it from here.
 *
 * @experimental the name and the shape may change before a pool outside this
 *               package implements it
 */
interface AgedItemsInterface
{
    /**
     * The value the last read of $key found reached by soft rules only, handed
     * out once: what it holds, its tags, and how many milliseconds ago the
     * newest of those rules fell. Null when there is none.
     *
     * @return array{value: mixed, tags: list<string>, ageMs: int}|null
     */
    public function takeAgedItem(string $key): ?array;
}
