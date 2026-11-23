# Replicas, clients, memory and deploys

Back to the [README](../README.md#how-it-works).

Run again on 2.2.4 on 2026-11-23 (symfony/cache 8.1.11, Redis 8.10.1, PHP
8.5.12 with phpredis 6.3.0; `used_memory` from one run): every number on this
page matched, a million items' memory within a megabyte, except those marked
†, which are that run's.

## Replicas

**The setup.** On a cluster with `failover=slaves`, phpredis sends a cluster's
read-only commands to replicas and everything else to the primaries. A replica
lags behind its primary by whatever replication takes.

**The question.** Can a read that a lagging replica answers serve a value the
rules have already invalidated?

**Example.** At 12:00:00.000 `product-42` is invalidated on the primary. A
replica is 200 ms behind. At 12:00:00.100 a worker refreshes its rules from
that replica: the rule is not there yet, so the worker's set stays as it was,
and it fetches the rule on a later refresh — the invalidation is seen late by
the lag, on top of `rulesCacheMs`. A read of the item itself from that replica
sees the item as it was 200 ms ago: older, or missing, never a value the set
the worker holds has invalidated. A fetch that comes back as if the stream had
been lost — the id the worker holds is not there, or nothing is — is not
believed: the same range is asked of the primary before anything is dropped.

| command | goes to | what a lagging replica does to it |
|---|---|---|
| `HMGET` (a read) | a replica | an item looks older, or missing: a miss where the primary has a hit, never a value the set has invalidated |
| `XRANGE` (a rule fetch) | a replica | a rule arrives late by the lag, on top of `rulesCacheMs` |
| `EVAL` (an invalidation, a confirmation) | the primary | nothing |
| `EVALSHA` (the write that follows a read) | the primary | a read that saw an older epoch, or missed a tombstone, makes the write refuse while the primary holds the newer one: it never lands where a write after a read of the primary would not |
| `HSETEX`, `EXPIRE`, `HDEL`, `UNLINK` (writes) | the primary | nothing |

So a lagging replica can make a read stricter, or an invalidation later by the
lag, and never serve a value past an invalidation the set already holds. The
price is one more round trip in a rare case: the primary is asked again when a
replica's answer is in doubt.

**How it works.** A replica that lags behind the rule id a worker holds
answers a fetch exactly as a lost stream would, and a lost stream is a reason
to drop the set. So on a connection that may read from replicas, that answer
is asked again of the primary — the same `XRANGE`, as a one-line `EVAL` — and
only the primary's answer may drop anything; so is a fetch that ends before a
rule this server just appended. On such a connection the writes go out as
typed `hsetex()`, `expire()`, `hdel()` and `hpexpire()` calls: phpredis sends
a raw command to the node the last read of its slot went to, a replica, which
refuses it. **`failover=slaves` therefore needs phpredis 6.3 or later**, the
first with `hsetex()`; an older one keeps the raw command, and its writes fail
once a read of the same slot has gone to a replica. Relay's cluster client is
given the same typed calls; CI runs Relay against three masters, but not
reading from replicas, so that path is untested.

## Clients: standalone, cluster, `RedisArray`, key prefix, Relay

**The setup.** Every command the adapter issues names a single key but three:
`SCAN`, with which a prune walks the masters one by one; `TIME`, which reads
the server's clock for a soft invalidation and is sent to the node that holds
the rules stream; and an `UNLINK` of several items, which a cluster client
splits by slot. So three masters are no different from one. The two clients,
ext-redis and Relay, route a command by the key it names; a `RedisArray`
routes a script by the node its body hashes to, and a client-side key prefix
(`OPT_PREFIX`) is put on keys by the client for typed calls and scripts but
not for raw commands.

**The question.** Does each command reach the node that owns its key, with the
key spelt the way the server stores it?

**Example.** A pool with the namespace `app` on a `RedisArray` of two nodes,
the client prefixed `shop:`. The rules stream is `shop:app:@rules`; node 2
owns it. An invalidation is a script, which the array would run on whichever
node its body hashes to — so the adapter sends it to node 2 itself. A read of
`app:article-7` is a typed `hMGet`, which the array routes to the key's owner
and the client prefixes. A raw `HSETEX` carries no prefix from the client, so
the adapter writes `shop:app:article-7` into the command and sends it to the
node that owns the bare key.

- **Standalone and cluster:** every command is routed by its key by the
  client itself; the adapter prefixes the keys of its raw commands and
  leaves the address — the key a cluster hashes for the slot — bare, as a
  prefixed address would be prefixed twice. On a phpredis cluster the writes
  go out as typed calls, which follow a `MOVED` or `ASK`. A raw command by
  key follows neither: phpredis 6.3.0 throws, on a `MOVED` until a typed
  command of the same slot has updated the client's map, so during a
  resharding a raw write of a key already moved fails. On Windows a phpredis
  cluster's writes stay raw commands, as the typed calls carry their numbers
  in 32 bits there (unless the reads go to replicas): writes of keys already
  moved fail while their slot is moved, and are reported failed.
- **`RedisArray`:** no raw command of its own and nothing to pipeline across
  nodes, so every call goes one at a time to the node that owns its key, and
  every command naming the rules stream to the node that owns it.
- **Relay:** the same code paths as ext-redis; the adapter should not be able
  to tell which one it is holding. After a guarded write, Relay's in-process
  cache is told to take in the server's invalidation, so another connection's
  cached miss is not served past it.
- **A cluster with phpredis's cluster pipelines** (not yet released): the
  adapter sends each batch as one pipeline across the slots, one round trip
  per master. A `getItems()` of 100 keys on three masters is 3 round trips
  instead of 100, and 100 misses with the batch write after them 6 instead of
  200; a `get()` loop does not change, one key per round trip. phpredis
  refuses a raw command in a cluster pipeline, which the typed writes avoid;
  on Windows the raw writes go one command at a time. phpredis gives up a
  whole pipeline on a `MOVED` or `ASK`, which a slot being moved answers, and
  the adapter then sends the batch again one command at a time, which follows
  the redirect: during a resharding a batch is slower, not a miss. A guarded
  write that had landed in the first run is then refused the second time:
  `save()` or `commit()` answers false and the log says the item was deleted
  or written by somebody else, though the value is stored. That is the safe
  direction. A pipeline that failed only after the client's read timeout is
  not sent again: every call of it failed, at the cost of one timeout.
  Symfony then tries a failed delete once more key by key, a timeout for each
  key on that master. That needs a read timeout: with `read_timeout=0`,
  Symfony's default, a failed pipeline is always sent again, and a node that
  does not answer is waited for twice. With `failover=slaves`, phpredis sends
  a pipeline's reads to the primaries.
  `ClusterPipelineTest` pins that path. Without cluster pipelines, a cluster
  gets one command per round trip.

So the adapter runs on a single node, a cluster, an array and through Relay,
with or without a client-side prefix, and CI covers every one of those legs
(see [Requirements](../README.md#requirements)).

## The hash encoding: memory per item

**The setup.** An item is a four-field hash — value, tags, watermark and the
guard's epoch — and Redis keeps a hash as a compact `listpack` only while
every field fits `hash-max-listpack-value` bytes, 64 by default. Most cached
values are larger, so by default an item is a `hashtable`; the expiry on its
fields adds about 70 bytes† to it (65† as a `listpack`), and the epoch about
60 (30 as a `listpack`).

**The question.** How much more does an item cost here than
`RedisTagAwareAdapter`'s string, and what can be done about it?

**Example.** A 100-byte value is 476 bytes as a `hashtable` against 195 for
`RedisTagAwareAdapter`'s string: 488 MB† for a million such items where
`RedisTagAwareAdapter` holds 249 MB. With `hash-max-listpack-value` raised to
4096 the same item is 280 bytes. At 2.6 KB values — bastnic's — a million items
take 3 265 MB† by default, 3 021 MB with the limit raised, where
`RedisTagAwareAdapter` holds 3 025 MB: the gap is small either way.

- **Leave the default:** small values cost about twice what a string costs.
- **Raise `hash-max-listpack-value`** to 4096: a standard setting
  (`redis.conf`, `CONFIG SET`, an ElastiCache or MemoryDB parameter group;
  Valkey has the same name), and with four fields per item a `listpack`
  costs nothing to search. It applies to every hash on the server, so weigh
  it against hashes with many large fields elsewhere.

So the memory per item is set by Redis' hash encoding rather than by the
rules, and the limit is the knob. Per item, as each adapter writes it
(`MEMORY USAGE`, Redis 8.10.1, symfony/cache 8.1.11;
`php benchmarks/memory.php`, which also takes the epoch, the field expiry and
a tombstone apart):

| value | **versioned**, limit 64 (default) | **versioned**, limit 4096 | `RedisTagAwareAdapter` | `TagAwareAdapter` |
|---|---:|---:|---:|---:|
| 100 B | 476 B (`hashtable`) | 280 B (`listpackex`) | 195 B | 195 B |
| 2 600 B | 3 388 B (`hashtable`) | **2 781 B** (`listpackex`) | 3 107 B | 3 107 B |

`MEMORY USAGE` leaves the allocator's overhead out, so for planning read what
the server holds: a million 2.6 KB items, one tag each (`ban.php tags=1000
entries=1000 bytes=2600`, `used_memory`):

| | **versioned**, limit 64 | **versioned**, limit 4096 | `RedisTagAwareAdapter` | `TagAwareAdapter` |
|---|---:|---:|---:|---:|
| before the ban | 3 265 MB† | **3 021 MB** | 3 025 MB | 2 997 MB |
| after it | 3 265 MB† (kept) | 3 021 MB (kept) | 2.8 MB† (freed) | 2 997 MB (kept) |

## Deploying a new version

During a rolling restart, old and new workers share one entry as long as both
write the same format, which has been 3 since 2.0.0. A release that changes
the format makes them miss each other's entry — the entry says which format
wrote it — and both load from the stream until the old ones are gone. Items
need nothing: a reader without the guard ignores the
epoch, and one with it takes an item without an epoch for one written before
it.
