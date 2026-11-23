# The rule set

Back to the [README](../README.md#how-it-works).

Run again on 2.2.4 on 2026-11-23 (symfony/cache 8.1.11, Redis 8.10.1, PHP
8.5.12 with phpredis 6.3.0 and APCu 5.1.28): every number on this page that a
benchmark produces stayed within the spread between runs or within 20 % of the
new middle value. The cost of a share (0.28, 1.28 and 1.67 ms) has no
benchmark of its own and was not run again.

## The rule set a read is judged against

**The setup.** To judge an item, a worker needs the rules. It cannot ask Redis
for them on every read — that would be the round trip `TagAwareAdapter` pays
for its versions — so it keeps a compacted copy in memory: the newest rule per
tag, one integer each, plus the clears. The copy is refreshed from the stream
at most once per `rulesCacheMs` (1 000 ms), and a refresh fetches only what was
appended since the copy's last id.

**The question.** How old may that copy be — which is to say, how long can an
invalidation made on another server, by a cron job or by a CLI process go
unseen?

**Example.** At 12:00:00.000 a price changes on server A and `product-42` is
invalidated. On server B a worker refreshed its copy at 11:59:59.400. That copy
does not hold the rule, so B serves the old price until its next refresh, which
happens on the first read after 12:00:00.400. The old price is gone within
`rulesCacheMs` of the invalidation, 1 s at worst while refreshes land at once,
and a second more while one is in flight.

- **`rulesCacheMs: 0`:** every read that can take the reload flag refreshes
  first; while another worker holds it, a copy up to a second old judges the
  read, and an older one waits for that reload, at most a second (case 5
  below). The old price is gone within a
  second, and every read costs up to one round trip more (`XRANGE` from the
  last id held, which usually answers nothing).
- **`rulesCacheMs: 1_000`**, the default: the old price may be served for up
  to a second, two while a refresh is in flight, and the refreshes cost the
  server one `XRANGE` per second, however many reads it serves (one per worker
  without a shared set).
- **`TagAwareAdapter`'s answer** to the same question is 150 ms per tag, and a
  fetch of every tag the read carries whenever the window has passed.

So `rulesCacheMs` bounds how long an invalidation made elsewhere goes unseen,
counted from the moment the last refresh began, and buys reads that cost no
round trip for the rules. An invalidation made on the same server is never
served stale there while the stream can be read, after a `reset()` too; see
[the shared rule set](#the-shared-rule-set-while-it-reloads).

**How it works.** A refresh is an `XRANGE` from the last rule id the set holds,
in batches of ten thousand rules, absorbed into the set; a read is judged
against the newest rule per tag it carries, so neither the refresh nor the read
grows with the length of the log. The process that made an invalidation adds
the rule to its own set from the id Redis answered, where its set ends at the
rule the new one follows, so it sees its own invalidation at once, and shares
that set with the other workers of the server — in the call, for the first
invalidation of a request, and when the request ends for the ones after it;
otherwise its set is behind, and is not judged by until it is reloaded. While the stream
cannot be read at all, a worker keeps judging by the set it last read, however
old: reporting "no rules" would let an invalidated item read as fresh, which
is the one answer this must never give. One worker per server tries the
stream again a second after the last attempt ended (see
[the shared rule set](#the-shared-rule-set-while-it-reloads)). A worker
holding no set reads every item as a miss until the rules are back, drops
nothing on a verdict it could not reach, and stores nothing it computes
meanwhile.

**Measured.** Ten thousand invalidations behind a fresh fill cost twenty
thousand reads three commands in all — one refresh per second, each carrying
nothing, since the pool that read had made the invalidations itself and held
every rule already (the
[`read hit, 10 000 rules behind` row](measurements.md)).

## The shared rule set while it reloads

**The setup.** Every tag invalidation writes a rule into Redis, like "items
tagged `product-42` written before 12:00:00 are stale". To check an item
without asking Redis every time, each PHP worker keeps a copy of all rules in
memory, shared through APCu by all workers on a server. That copy goes out of
date whenever another server, a cron job or a CLI process invalidates
something. So about once a second (`rulesCacheMs`), one worker per server
takes a flag and reloads the copy, and the others pick up its result. Nobody
else fetches rules, unless the store refuses to record the flag (below).

**The question.** What should the other workers do while that worker is still
fetching? This happens all the time, but usually the fetch takes 1–2 ms, so
the answer barely matters. It matters when the fetch is slow: Redis under
load, a large rule set, or the Redis node holding the rules failing over or
hanging.

**Example.** At 12:00:00 a price changes on server A and `product-42` is
invalidated. On server B, the worker holding the flag starts fetching, but
Redis is struggling and the fetch takes 3 seconds.

- **"Wait for the reload":** every request on server B waits for it. The price
  is correct, but the whole site slows down exactly when Redis is struggling.
- **"Fetch on your own":** every request on server B fetches the rules itself,
  and a Redis node that hangs makes every one of them wait for its own
  timeout.
- **What this adapter does:** a worker whose copy is at most a second past
  the window judges by it, at once, for the first second of the fetch. One
  whose copy is older, behind an invalidation made on B, or missing waits for
  the fetch (it is one round trip when Redis is well) and is judged by its
  result; when another worker's reload follows at once, it looks again as
  soon as the flag changes hands rather than wait out the second. After a second the fetch counts as an outage for everyone else:
  every worker judges by the copy it holds, whatever its age, until Redis
  answers; one that holds none reads every item as a miss, computed without
  the stampede lock. Server B serves the old price until 12:00:03. Requests
  that came with a usable copy never waited; the others waited at most a
  second, once. Why a second and not less: Redis runs one command at a time,
  so a long command of anyone sharing it holds the fetch up as well, and the
  requests wait for Redis meanwhile anyway; giving up sooner would bring the
  stampede back in just those moments. What a reload has to log (a failed
  fetch) reaches the logger once the flag is let go, so a slow logger, or one
  that reads this pool, holds no other worker up and does not wait for itself.

What that promises, in plain words:

- While Redis is healthy and a reload lands within a second, an invalidation
  made elsewhere is seen within `rulesCacheMs` plus a second, plus the lag
  when reads go to replicas. A read whose copy cannot promise that waits for
  the reload in progress, at most a second, and is judged by the copy that
  reload shares; past that second the reload counts as hanging (below).
- While the rules can be read, an invalidation made on this server is never
  served stale on this server: a copy behind it judges no read until it is
  reloaded. During an outage, or while a reload hangs past a second, a copy
  behind it judges reads like any other, and the invalidation is seen once
  the stream answers again.
- During an outage, or while a reload hangs past a second, invalidations made
  elsewhere are seen when Redis answers again, and nobody but one request per
  server per second fetches rules.
- No value computed after a read without rules, or without its epoch, is
  stored. Past the guard window that read still refuses its write, once, and
  is then forgotten.

A reload in flight costs at most a second of a slightly older copy or of
waiting, then staleness. The reloader waits for as long as its fetch's
timeouts allow, so keep them short: a second or two to connect and to read:

```php
// in the DSN
$redis = RedisAdapter::createConnection('redis://127.0.0.1?timeout=1&read_timeout=1.5');

// or on a connection of your own
$redis->connect('127.0.0.1', 6379, 1.0);
$redis->setOption(\Redis::OPT_READ_TIMEOUT, 1.5);
```

Left at their defaults, Symfony gives the connect 30 seconds and phpredis waits
`default_socket_timeout`, a minute, for a reply, and so would the reloader. A
fetch that fails puts the server in an outage (below).

**Why the set is shared at all.** Under PHP-FPM every request is a fresh
process. A process that holds no rules would have to load the whole stream —
every invalidation of the retention window — before its first hit. Shared, the
compacted set is loaded once per server and then only followed; a fresh
process adopts it instead. `rulesCache` says where: by default APCu, where
it is enabled, through the `apcu_*` functions rather than an `ApcuAdapter`;
any PSR-6 pool you pass otherwise; or `false` to keep the set per instance.
APCu is spoken to directly, because it keeps a
PHP array as it is and hands back a copy, where a pool serialises it on the
way in and parses it on the way out, and that cost is paid per request, by
the size of the set. The processes that share one medium are one server: the
reload flag, the outage and the mark of the newest invalidation live beside
the set and speak for all of them. So pass a pool per server, as APCu is; one
shared by several servers makes them one server, with one reloader and one
outage among them.

**How it works.**

- The flag is a lease kept beside the set: an owner token and the moment the
  reload began. One worker at a time takes it — APCu adds or swaps it in one
  step; in a pool you pass, a short file lock on the host serialises the look
  and the write — and only its owner releases it. It outlasts any reload: it
  is there for a worker that dies, not for one that hangs. A lease older than
  30 seconds is free again, and a worker that dies of a fatal error lets go of
  it on shutdown.
- A store that refuses to record the flag — APCu while another process
  expunges it, a pool on a full or read-only disk — would leave the server
  with nobody to reload, and every read a miss for good. So a worker that
  finds the flag free but cannot take it is a server of one for that read: it
  loads the rules itself, from the last id of the set it holds, shares
  nothing, keeps its outage to itself, and logs a warning, once per pool
  instance. It costs one fetch of the stream per request while the store
  refuses, as `rulesCache: false` does. A store that takes the flag but
  refuses the rule set itself leaves the others judging by the older set
  there; the worker whose set was refused logs a warning, once per pool
  instance.
- A read takes the first of these that applies:
  1. a copy read less than `rulesCacheMs` ago and not behind an invalidation
     made on this server judges it, at no round trip — never with
     `rulesCacheMs: 0`;
  2. during an outage, or while a reload has run for more than a second, the
     copy judges it whatever its age, a copy behind included. With none,
     every item is a miss, computed without the stampede lock: nothing it
     computes is stored, so the lock would only queue the requests for a key
     one behind another. One worker tries the stream again when the flag is
     free and a second has passed since the last attempt *ended*;
  3. with the flag free, the worker takes it and reloads: `XRANGE` from the
     copy's last id, shared with the others when it lands — in place of their
     copy where the stream started over, as the primary confirms. A fetch
     that fails, an error reply (`BUSY`, `LOADING`) included, starts an
     outage, and only a fetch that reads the stream ends it;
  4. while another worker's reload has run for at most a second, a copy at
     most `rulesCacheMs` plus a second old and not behind judges it — with
     `rulesCacheMs: 0`, a copy up to a second old;
  5. anything else — an older copy, one behind, none — waits for that
     reload: it looks at the flag after 0.1 ms, then after twice as long each
     time, up to 5 ms, and when the flag is free takes the first of these
     cases again. Usually that is case 1, with the copy the reloader shared, a
     round trip after the wait began. If the reload failed it is case 2; if it
     has run a second, case 2 as well. Only a read that has waited a second in
     all gives up: every item is then a miss, computed without the stampede
     lock and, with a copy, stored stamped with the copy's last rule.
- A copy's age counts from the moment the fetch that read it *began*. A
  failure reported by a reload that began before the last success is ignored.
- A write that follows no read takes its copy the same way — so it can become
  the reloader — and is stamped with the copy's last rule id; the stream's
  head is never read for a write. With no copy it is not stored, and the
  value it would replace is deleted (see [the guard](guard.md)).
- An invalidation made on this server: where the invalidating process's copy
  ends at the rule the new one follows, it adds the rule from the id Redis
  answered. A process that holds no copy yet (a request that only
  invalidates), or an older one than the server shares, takes the shared
  copy first: one read of the rule set store, no round trip. The reply proves that copy holds everything appended before the
  call, so it is stamped with the moment just before it, and the others take
  it for as fresh as that. A mark beside the shared set records the newest
  rule a process of this server appended, and the time after the reply; a
  copy read before the mark that lacks the rule is behind: it judges no read
  until it is reloaded, through the flag like any reload — never by a fetch
  of its own. The first invalidation of a request — a pool since its last
  `reset()` — shares its copy at once, in the `invalidateTags()` call,
  before it marks the server: a worker that sees the mark finds a shared
  copy that already holds the rule, adopts it, and judges by it — no fetch,
  no misses. That covers the usual save: one call. Later invalidations in
  the same request share nothing in the call; the copy is shared once when
  the request ends, by `reset()` (Messenger's `services_resetter` between
  messages calls it) or when the pool goes away, never at a read. Until then
  the other workers are behind those later rules, and reload through the
  flag. Where another rule landed in between, nothing is shared either.
  During an outage, or while a reload hangs past a second, a copy behind
  judges reads as any copy does, and the invalidation is seen once the
  stream answers again. The mark only moves forward: its time and its rule
  id are each raised in one step — a compare-and-swap in APCu, the host's
  file lock in a pool you pass — so two invalidations racing on one server
  leave the later one's.
- A share stores the whole copy and reads the entry back first, so a newer
  set is not overwritten: about 0.28 ms for a set of 2 000 rules in APCu,
  1.28 ms for 10 000 and 1.67 ms for 15 000 (in a pool you pass, what a
  write to that pool costs). A request pays it at most twice, however many
  `invalidateTags()` calls it makes: once in its first call, once at its
  end; shared in every call, ten thousand calls of one tag would pay it ten
  thousand times. Each call is still a round trip, so passing the tags of one
  change in one `invalidateTags()` call stays the cheapest.
- `reset()` keeps the copy and the moment it was read; what a request read
  goes with the request.
- With `rulesCache: false` a worker is a server of one: its copy, its flag and
  its outage are its own, and another instance sees a rule as one made
  elsewhere.
- The entry is keyed by the rules key and the connection's identity (host,
  port, database, the masters of a cluster, a client-side key prefix), so two
  pools on one server that talk to different Redis servers, or under
  different prefixes, never read each other's rules. The entry says which
  format wrote it: in APCu, which only this package's processes write, a
  fresh process adopts an entry of its own format as it is, without checking
  each rule, and one of another format is a miss; in a pool handed in, where
  others may write too, every rule is checked.

**Measured.** Adopting the ten-thousand-tag set costs about 1.1 ms per fresh
pool and asks Redis for nothing; without sharing, a fresh pool loads the
stream: 4 000 more commands, about 19 ms per pool (the two
[`fresh pool every 10 reads` rows](measurements.md)). What a set of a
given size costs to adopt is in
[the rule set a fresh process adopts](measurements.md#the-rule-set-a-fresh-process-adopts).

## The bound and its floor

**The setup.** The rule set holds one integer per tag invalidated within the
retention window, and every request copies it. A shop that invalidates
`product-N` on every price change holds as many rules as products changed in
the last 30 days; an application with a tag per entity can hold a hundred
thousand.

**The question.** What should the set do when it grows past what a request
can afford to copy?

**Example.** 100 000 distinct entity tags have fallen behind a fill of 20 000
items. Unbounded, the set is 4 MB in APCu and costs every request about 10 ms to
adopt. With the bound at 15 000, the set keeps its newest 13 500 rules and puts
a **floor** in place of the rest: a clear of the whole pool at the newest id it
dropped. Whatever a dropped rule invalidated, the floor invalidates too — so
the 20 000 items, all older than the floor, read as a miss and are computed
once, and an item written after the floor keeps its verdicts.

- **`rulesMaxTags: 0`**, no bound: every hit is kept, and a 4 MB set is copied
  by every request (10.49 ms).
- **`rulesMaxTags: 15_000`**, the default: the set stays a few hundred KB
  however many distinct tags fall (1.00 ms to adopt), and items older than
  the floor are recomputed once. The 10 % of headroom keeps a set at its
  bound from being cut again by every rule that follows.

So the bound trades one recomputation of the items older than the floor for a
set whose size no longer depends on how many distinct tags fall. The floor
never serves a stale hit: an item it reaches reads as a miss.

**How it works.** Tags and clears count together. Past the bound the set sorts
its rule ids, keeps the newest 90 %, and prepends a prefix rule for the whole
pool at the newest id beyond them. A set is also trimmed by the retention —
rules older than the longest possible item lifetime have nothing left alive to
match — and by a clear of the whole pool, which says everything an older rule
could say.

**Measured.** A set costs about 40 KB in APCu and 0.1 ms to adopt per
thousand rules; see [the rule set a fresh process adopts](measurements.md#the-rule-set-a-fresh-process-adopts)
for the table, and the `behind=100000` table there for the hit count behind a
hundred thousand entity tags.
