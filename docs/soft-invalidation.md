# Soft invalidation

Back to the [README](../README.md#soft-invalidation).

Run again on 2.2.4 on 2026-11-23 with cache-stampede 2.1.2 (symfony/cache
8.1.11, Redis 8.10.1, PHP 8.5.12, the middle of five runs): every number on
this page stayed within the spread between runs or within 20 % of the new
middle value.

**The setup.** HTTP caches have done this for years: Fastly's soft purge marks
content stale instead of deleting it and serves it while it revalidates,
Varnish's `xkey.softpurge()` keeps an object for grace mode, and
FOSHttpCacheBundle invalidates tags softly by default. Here a tag invalidation
is soft unless it says otherwise, and `softGraceSeconds` says how long an item
a soft invalidation reached is kept for a consumer that may serve it stale.

**The question.** When a tag that 300 page fragments carry falls, must every
visitor wait for the fragments to be recomputed, when the old version was fine
for another minute?

**Example.** At 12:00:00 an editor edits the menu and invalidates `menu`.
Twelve visitors arrive at once; each page reads 25 of the 300 fragments that
carry the tag, and a fragment takes the origin 20 ms.

- **Hard invalidation** (`hard: true`, or any pool without a grace): the first
  reader of each fragment computes it. All 12 visitors wait, 551 ms at the
  median, 552 ms at most, and 300 origin calls run during the responses.
- **Soft invalidation with a grace**, read through
  [`artigo/cache-stampede`](https://github.com/artigo-dev/cache-stampede)'s
  `StaleCache` in soft mode: every visitor is served the old fragments at
  once, 18 ms for the 25 reads, 18 ms at most. The 300 refreshes run after the
  responses, one per fragment, elected through `MemoLock`. No visitor waits.

So soft invalidation trades serving the old fragment for up to the grace
against making visitors wait for its recomputation; a price, a stock level or
anything that must never be served again is invalidated hard.

```php
$pool->invalidateTags(['menu']);               // soft
$pool->invalidateTags(['price-42'], hard: true); // never served again
```

**How it works.** With `softGraceSeconds`, a read that finds an item only soft
rules reached, the newest of them less than the grace ago, does not drop it.
The read is a miss all the same — every PSR-6 and `get()` read stays strict —
and the item is kept for `takeAgedItem()` on the `@experimental`
`AgedItemsInterface`, which `StaleCache` reads in soft mode: it serves the old
value stale and refreshes it once. Nothing else consumes it, and without a
grace the two kinds of rule read alike. `0`, the default, drops such an item
as a hard rule would.

- **Who opts in.** The value: a `StaleCache(..., soft: true)` pool writes
  values that may be served after a tag invalidation; any other value is a
  miss. And the invalidation: `hard: true` overrules every value.
- **What stays hard.** A hard rule, a delete, a clear, the bound's floor and a
  lost stream. The newest hard rule per tag is kept beside the newest rule, so
  a soft invalidation after a hard one leaves the hard one standing. What a
  read kept is handed out once, and goes before that with the next read,
  write or delete of the key through the pool; a clear, a hard invalidation
  or a `reset()` through the pool drops every kept copy. So a value this pool
  wrote, deleted, cleared or invalidated hard after its read is never handed
  out. A change made through another pool or process does not reach a copy
  already kept: the copy lasts until this pool next reads the key, or one of
  the above drops it. A pool keeps a thousand such copies at most, the oldest
  forgotten first.
- **`hasItem()` and `prune()`** judge as a read does: such an item is a miss,
  and stays on the server for the read that may keep it, until its grace has
  passed.
- **The window** counts from the newest invalidation that reached the item,
  on the server's clock. A process reads it once, carries the reading on with
  its monotonic clock, and reads it again once a minute, so a long-running
  worker follows a server clock that was stepped or a failover to another
  node. `softGraceSeconds` bounds how long an item is kept for it, and
  `StaleCache`'s stale and error times apply within that.
- **No mark to lose.** The invalidation time is the rule's own stream id, so
  nothing is written to an item when its tag falls, and a lost rule is a hard
  miss, never a stale hit. An implementation that marks items instead — a
  stamp beside a tag, a flag on a value — has to fail hard when the mark is
  evicted, or the mark becomes the way an invalidated value is served.
- **And the guard.** A computation that straddles a soft invalidation is
  stamped where its read was, so it reads stale, and comes back only aged —
  never fresh.

**Measured** with real processes (`benchmarks/soft.php`, cache-stampede's
`StaleCache` in soft mode over this adapter with `MemoLock`): 300 fragments
carry one tag, twelve visitors arrive at once after it falls, each page reads
25 of them, and a fragment takes the origin 20 ms:

| invalidation | visitors who waited for the origin | wait p50 | wait max | origin calls during the responses | after them |
|---|---:|---:|---:|---:|---:|
| hard | 12 / 12 | 551 ms | 552 ms | 300 | 0 |
| **soft** | **0 / 12** | **18 ms** | **18 ms** | 0 | 300 |

The soft wait is the 25 reads; the 300 refreshes run after the responses, one
per fragment, elected through `MemoLock`. Redis 8.10.1, PHP 8.5.12, this
package 2.0.1 with cache-stampede 2.0.0 over symfony/cache 8.1.11, the
benchmark in a container on the Redis container's network; the middle of three
runs.
