# The invalidation guard

Back to the [README](../README.md#the-invalidation-guard).

Run again on 2.2.4 on 2026-11-23 (symfony/cache 8.1.11, Redis 8.10.1, PHP
8.5.12 with phpredis 6.3.0 and APCu 5.1.28; the middle of five runs for the
cost table): every number on this page stayed within the spread between runs
or within 20 % of the new middle value, except those marked †, which are that
run's.

**The setup.** Every cache that computes on a miss runs a race, in three
steps:

1. A request misses `article-42` and starts computing it from the database.
2. Meanwhile the article changes, and whoever changed it calls
   `invalidateTags(['article-42'])` or `deleteItem('article-42')`.
3. The computation finishes and saves what it read before the change. The
   invalidation found nothing to invalidate, so the old article is served
   until its TTL runs out.

**The question.** Can a value computed from data that was deleted or
invalidated while it was computed ever be stored as fresh?

**Example.** At 12:00:00.000 a request misses `article-42` and starts a
computation that takes 4 ms. At 12:00:00.002 an editor saves the article and
invalidates `article-42`. At 12:00:00.004 the computation saves the old
article.

- **`TagAwareAdapter`:** keeps the tag invalidation out only where the
  computing process knew the tag's version before its miss — a long-running
  worker that fetched it within 150 ms. A fresh pool, the PHP-FPM case, knows
  none, so the old article is stored as fresh and served for its TTL. A
  delete during the computation is never covered.
- **`RedisTagAwareAdapter`:** the old article is stored and served, for a tag
  invalidation and for a delete alike (922 and 927 of 1 000 rounds with one
  computing process, in the measurement below).
- **This adapter, the guard on (default):** the write is stamped with the
  rule its read was judged against, `1000-0`, not with the stream's head. The
  rule from 12:00:00.002 is newer than the stamp, so the next read finds the
  item stale: written, read as stale. For a delete, the write is refused
  outright: `save()` answers `false`, and the next read computes afresh.

So the guard closes the race: a value computed across an invalidation is never
served as fresh past it. The price is two more commands on the write that
follows a miss, three commands instead of one for a delete, and a tombstone
per deleted key for the window.

**How it works.** **Every write leaves an epoch**, a random token in a fourth
field. **A delete leaves a tombstone**: for `guardWindowSeconds` (60) the key
holds a fresh epoch and nothing else, which every read takes for a miss.
**The write that follows a read of this pool is one script**, which refuses
where the key holds an epoch other than the one the read saw, lands where it
holds that one or none, and stamps the item with the rule its read was judged
against rather than with the stream's head. So a delete during the
computation refuses the write while its tombstone lasts, and a tag
invalidation or a clear during it is newer than the stamp: the write lands,
and the next read finds it stale. A key with no epoch at all was not deleted
or written within the window, since both leave one for that long: the epoch
the read saw ran out by itself, and the write lands.

The script goes out as `EVALSHA`; where the server does not hold it — after a
`SCRIPT FLUSH`, a restart or a failover — it goes again as `EVAL`, once per
node. A write that follows no read stays the plain `HSETEX`, and an
invalidation stays the one script it always is (four commands): the guard
adds no script beyond the write that follows a read.

**Which writes are guarded.** The pool remembers, per key, the reads a write
may follow: a miss, a tombstone, a stale item, a value that does not
unmarshal, a hit that `get()` is about to recompute (`$beta = INF`, or an
early expiration). A read made with no rule set held is remembered too, and
the write after it is not stored: the caller gets its value. Where `get()`
computed it after its own miss, the key stays as it was, for the rules to
judge once they are back; any other write — a forced recomputation, a save
after that read, a plain write while no rule set is held — was to replace the
value, so it deletes it, and the value it was to replace is not served.
Which of the two a write is travels with the write, key by key; it is not
guessed from the reads remembered. A read of the item that fails — an error,
or a timeout raised from the pipeline — does not know the item's epoch: the
write after it is not stored either, by the same rule. (With
`guardWindowSeconds: 0` nothing is remembered: a write after such a read is a
plain one. With no rule set held, `get()`'s own computation after its miss
still leaves the key as it was, and any other write unlinks the value it
would replace.)
Such a doubtful read refuses every write after it, however old it is, until
the key is read again: a save tried again may be the same value, computed from
what that read could not see. The end of the `get()` that made the read
forgets it, and `reset()` forgets it at once; so do a thousand newer reads,
which push it out, and a save after that is a plain write again. A caller
that keeps one item and saves it again and again without reading it writes a
tombstone each time, which deletes a value another process stored in between:
misses, never a stale value. A plain hit forgets the
read before it; so does a write that lands and the pool's own
delete of the key. The pool's own `invalidateTags()` and `clear()` move the
reads that had seen the stream up to them on to the new rule: a value
computed by the pool that invalidated is not judged stale by its own
invalidation, which `TagAwareTestTrait`'s `testInvalidateCommits` pins, while
a rule from elsewhere before it still counts and a delete from elsewhere
still refuses. A read that a rule from elsewhere followed before the pool's
own invalidation keeps its mark: a value it computes with the tag the pool
invalidated reads as a miss once — the safe direction. A pool remembers a
thousand reads besides its largest single fetch, the oldest forgotten first:
a batch of `getItems()` is guarded whole, however many keys it holds, and
past it the oldest single reads go first and their writes go out plain.
`reset()` and a clone forget them all.

**What callers notice.**

- `save()` and `commit()` answer `false` for a refused write; the adapter logs
  one info line, "Refused to save …: it was deleted or written by somebody
  else while it was computed", and Symfony its usual warning for a failed
  save. `get()` still returns the value it computed, and on Symfony 8.2 sets
  `ItemInterface::METADATA_SAVE_FAILED`. Symfony does not retry an id reported
  as failed, and the read stays remembered, so saving the same item again is
  refused too: read it again first.
- A deleted key exists for the window as a hash with one field, about 260
  bytes† of server memory (the guard's cost table below: 4.9 MB for 20 000;
  `MEMORY USAGE`, which leaves the allocator's overhead out, counts 180 for
  one that held a value above `hash-max-listpack-value` and 97 for one that
  stayed a `listpack`, `benchmarks/memory.php`); `prune()` leaves tombstones
  alone, and they expire by themselves.
- The epoch lives for the window at least, so an item written with a shorter
  TTL keeps its key, holding the epoch alone, until the window has passed.
- An invalidation inside the callback, made through the same pool, is the
  pool's own: the value is written and read as fresh. Made through another
  pool instance, it is a rule from elsewhere, and the value reads stale.
- `guardWindowSeconds: 0` switches it off: no epoch, a delete is an `UNLINK`,
  and every write is stamped when it lands, with the last rule id of the set
  it takes, as a write that follows no read is with the guard on.

**Where it stops.**

- A delete is seen by every write made within the guard window after it. If
  a value can take longer than the guard window to compute, raise the window
  above it: a computation that outlasts it writes after the tombstone has
  gone, and a delete early in it is not seen. Tag invalidations and `clear()`
  are always seen, whatever the computation takes: the stamp is the read's.
- `prune()` can remove a tombstone that a delete left a moment earlier, in the
  instant between its read of the item and its unlink. That is the same bound:
  a computation across that delete is stored.
- A pool instance with `guardWindowSeconds: 0` on the same keys deletes with
  `UNLINK`, which takes the tombstone away too, and writes without a new
  epoch. A guarded instance does not see those deletes and writes: give every
  instance of a pool the same window.
- A value computed across a soft invalidation reads as stale, but within the
  grace it can be served aged, as any value a soft rule reached
  ([soft invalidation](soft-invalidation.md)).
- One pool shared by coroutines (Swoole, AMPHP): one coroutine's invalidation
  moves another's read on. One pool per request has no such interleaving.
- `ChainAdapter::get()` reaches the last pool of its chain through `getItem()`
  and `save()`, not its `get()`: a miss is guarded there, a recomputed hit is
  not.
- A write with no read of the same pool instance before it —
  `Psr16Cache::set()`, a second pool — is a plain write, and lands over a
  tombstone as a write after a delete should.

**The three rows of #67391.** The reproduction behind
[#67391](https://github.com/symfony/symfony/issues/67391) and
[#67407](https://github.com/symfony/symfony/pull/67407) has three ways for a
computation to outlive an invalidation, and the guard covers all three on a
fresh pool, the PHP-FPM case
(`InvalidationGuardTest::testAColdTagIsCoveredOnAFreshAdapter`):

| during the computation | **versioned** |
|---|---|
| `invalidateTags()`, the tag's version fetched before the miss (warm) | written, read as stale |
| `invalidateTags()`, the miss is the first read of the tag (cold) | written, read as stale |
| `deleteItem()` | refused |

**Measured** with real processes (`benchmarks/race.php`, bastnic's `lab:race`
shape): one process sets a source value, makes the item a miss, waits part of
a computation, changes the source and invalidates again; other processes
compute the miss through `get()` all the while, with a callback that reads the
source and takes 4 ms. Counted is how often the old value is still served once
every computation that read it has written: out of 1 000 rounds, with one
computing process / with four. Each computation builds a fresh pool, as a
PHP-FPM request does, unless the row says one pool per process, as a
long-running worker keeps; the cluster is three masters with a replica each,
read from the replicas. Symfony's adapters are symfony/cache 8.1.11, #67407 its
head `7f926b3` over 8.2.0-BETA1, the versioned rows 2.0.1
(`php -d apc.enable_cli=1 benchmarks/race.php`, `stores=`, `warm=1` and `tree=`
for the other rows); one run per row. Run again on 2.2.4 on 2026-11-23, every
count came out within what a race of 1 000 rounds varies by, and the guard's
rows were 0 again:

| stack | tag invalidation | delete | tag invalidation, cluster | delete, cluster |
|---|---:|---:|---:|---:|
| `TagAwareAdapter` | 894 / 741 | 821 / 818 | 851 / 522 | 836 / 854 |
| `TagAwareAdapter`, one pool per process | 14 / 13 | 826 / 850 | | |
| `TagAwareAdapter` from #67407 | 873 / 635 | 836 / 807 | 820 / 221 | 788 / 858 |
| `TagAwareAdapter` from #67407, one pool per process | 16 / 14 | 840 / 841 | | |
| `RedisTagAwareAdapter` | 922 / 880 | 927 / 920 | 950 / 887 | 938 / 885 |
| **versioned**, `guardWindowSeconds: 0` | 4 / 4 | 861 / 855 | 5 / 3 | 872 / 849 |
| **versioned**, `guardWindowSeconds: 0`, `rulesCacheMs: 0` | 759 / 812 | 811 / 835 | | |
| **versioned**, the guard on | **0 / 0** | **0 / 0** | **0 / 0** | **0 / 0** |
| **versioned**, the guard on, `rulesCacheMs: 0` | **0 / 0** | **0 / 0** | | |

`TagAwareAdapter` keeps a tag invalidation out where the computing process
knew the tag's version before its miss — a long-running worker, which reuses a
version it fetched within 150 ms; a fresh pool knows none. #67407 (at
`7f926b3`) keeps more of them out where several processes compute at once,
never all, and like 8.1 it never covers a delete. Without the guard, this
adapter stamps a write with the rule set it holds while that set is younger
than `rulesCacheMs`, which keeps most tag invalidations out by the accident of
timing; with exact reads that set is reloaded as the write lands, so the
stamp is the stream's head, and 759 stale values survive. A delete is open either way. The rounds wait on counters, not on
timers: each computation is counted when it starts and when its write is done,
and the round is read once every computation that started before the change
has finished.

**Per tag, not per pool.** One epoch for the whole pool would be the simpler
guard, and the wrong one where something is invalidated all the time: under
ten unrelated invalidations a second, a hundred computations of 200 ms
(`race.php precision=100`):

| | writes refused |
|---|---:|
| one epoch per pool: every write a rule was appended during | 100 of 100 |
| **versioned**: a rule for one of the item's own tags | **0 of 100** |

**What it costs** (`bench.php guard=1`, 20 000 items, 18 tags each; commands ·
round trips · time; this package 2.0.1, the middle of three runs):

| | the guard on | `guardWindowSeconds: 0` |
|---|---|---|
| fill through `get()` misses | 100 006 · 40 006 · 6.5 s† | 60 005 · 40 005 · 6.3 s† |
| fill through `getItems()` + `commit()`, 100 a batch | 100 001 · 1 001 · 0.65 s | 60 001 · 1 001 · 0.56 s |
| read hit | 20 002 · 20 002 · 2.2 s | 20 002 · 20 002 · 2.2 s |
| delete every item, 1 000 a call | 60 000 · 220 · 0.13 s | 20 · 40 · 0.03 s |
| left after the delete | 20 000 tombstones, 4.9 MB, for the window | nothing |

The write that follows a miss is five commands where it was three: the script
holds three (`HGET`, `HSETEX`, `PEXPIRE`) for an item that lives at least the
window, and one `HSETEX` more for one that lives shorter, whose epoch gets the
window as its own expiry; so the two more are the `EVALSHA` itself and the
`HGET`, in the same round trips. A delete is three commands per key where it
was one `UNLINK` per call, and holds its tombstone for the window.
