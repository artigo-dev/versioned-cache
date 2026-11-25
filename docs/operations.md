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
typed `hsetex()`, `expire()`, `hdel()` and `hexpire()` calls: phpredis sends
a raw command to the node the last read of its slot went to, a replica, which
refuses it. **`failover=slaves` therefore needs phpredis 6.3 or later**, the
first with `hsetex()`; an older one keeps the raw command, and its writes fail
once a read of the same slot has gone to a replica. Relay's cluster client is
given the same typed calls; CI runs Relay against three masters, but not
reading from replicas, so that path is untested.

phpredis 6.3 does not send `READONLY` again when it opens a replica's socket
anew. The adapter closes a cluster connection only where a late reply may be
left on it: after a `SCAN` or a `TIME` that failed, or a reply cut short (see
[Timeouts and lost connections](#timeouts-and-lost-connections)); a typed
command that fails, a read or a write that timed out, has its node's socket
closed by phpredis alone and touches no replica. After such a close, every
read `failover=slaves` sends to a replica fails, after the client's timeout
plus its read timeout (2.5 s with `timeout=1&read_timeout=1.5`), until the
client is made again: under PHP-FPM, for the rest of the request. Those reads
are misses, never a wrong value, and the writes, which go to the primaries,
carry on; the adapter logs it once per pool. With `failover=distribute` the
replica's redirect is followed and the reads go on. Later phpredis releases
send `READONLY` again.

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
  resharding a raw write of a key already moved fails. On Windows the writes
  go out the same way: phpredis passes a typed call's numbers through 32 bits
  there, which hold the whole seconds the writes carry. But with phpredis
  6.3.0 on Windows and a client timeout (`timeout` or `read_timeout` above 0;
  a Symfony DSN has `timeout=30` unless it says otherwise), a command that
  meets a redirect fails with "Timed out attempting to find data in the
  correct node!", because phpredis's redirect timer overflows there. So
  during a resharding a read of a key already moved misses for as long as its
  slot is being moved, a `getItems()` holding one such key misses entirely,
  the first read of each slot after the hand-over misses once, and writes and
  deletes of keys already moved fail and are reported failed. Without a
  timeout the redirects are followed. It is a phpredis bug, fixed in phpredis
  after 6.3.0 and 6.4.0RC1, and not yet released.
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
  refuses a raw command in a cluster pipeline, which the typed writes avoid.
  phpredis gives up a whole pipeline on a `MOVED` or `ASK`, which a slot
  being moved answers, and the adapter then sends the batch again one command
  at a time, which follows the redirect (on Windows only without a client
  timeout): during a resharding a batch is slower, not a miss. A guarded
  write that had landed in the first run is then refused the second time:
  `save()` or `commit()` answers false and the log says the item was deleted
  or written by somebody else, though the value is stored. That is the safe
  direction. A pipeline that failed only after the client's read timeout is
  not sent again: every call of it failed, at the cost of one timeout.
  Symfony then tries a failed delete once more key by key, a timeout for each
  key on that master (see
  [Timeouts and lost connections](#timeouts-and-lost-connections)). With
  `read_timeout=0`, Symfony's default, the limit is PHP's
  `default_socket_timeout`. With `failover=slaves`, phpredis sends a
  pipeline's reads to the primaries.
  `ClusterPipelineTest` pins that path. Without cluster pipelines, a cluster
  gets one command per round trip.

So the adapter runs on a single node, a cluster, an array and through Relay,
with or without a client-side prefix, and CI covers every one of those legs
(see [Requirements](../README.md#requirements)).

## Timeouts and lost connections

**The setup.** A command that does not get its reply within the client's read
timeout throws. phpredis 6.3 then keeps the socket open after some failures:
on a single node after a raw command, a typed `EVAL` or `EVALSHA`, a `SCAN`,
any reply cut short, and a pipeline that did not go out whole (its `exec()`
answers false without throwing); on a cluster after a raw command by key, a
command sent to a given node (`SCAN`, `TIME`) and a reply cut short. The
reply that comes late is read as the next command's answer, and every reply
after it one command late, without an error, until the socket closes.
phpredis releases after 6.3 close the socket themselves.

**The question.** After a command that timed out, can a read answer with
another key's value, or a delete lose its tombstone?

**Example.** A worker's guarded write of `a` waits out its read timeout of
0.3 s, and Redis answers 0.5 s later. Its next read, of `b`, gets the write's
late reply, a miss, and leaves `b`'s own reply on the socket; its read of `c`
then gets `b`'s value, as a hit, and every read after it the value of the key
read before.

| | after a command that failed on the wire |
|---|---|
| phpredis 6.3, socket kept | every later answer one command late: other keys' values served as hits, tombstones unlinked on answers meant for other commands |
| **this adapter** | the connection closed where a late reply may be left; the client opens a new one for its next command |

**How it works.** Every command the adapter sends goes through one place, and
when a phpredis client throws there, the connection is closed where a late
reply may be left on it:

- On a single node, after any failure but an error reply of the server
  (`OOM`, `READONLY`, `LOADING`, `BUSY`, `NOPERM`) that phpredis raises: that
  reply was read to its end, and phpredis keeps it as the last error, word for
  word, which a timeout, a reset or a closed socket never is. But an error
  reply that also waited out the read timeout, or follows another exception,
  may have left a reply behind (a pipeline whose first reply timed out and
  whose later ones were errors), and closes the connection too.
- On a cluster, after a `SCAN` or a `TIME` sent to a node, a raw command, or a
  reply cut short. A typed command that fails has its node's socket closed by
  phpredis itself; closing the client as well would open every node again,
  and with phpredis 6.3 the replicas would not be read again (see
  [Replicas](#replicas)). One gap is left on phpredis 6.3: while a slot is
  being moved, a typed command whose `ASKING` times out throws "Can't
  communicate with any node in the cluster" with that node's socket still
  open and the late `+OK` on it, which the adapter does not handle; later
  phpredis releases close that socket themselves.
- On an array, each node is a single node of its own. An unlink across nodes
  goes to each node on its own, in the order the array would send them, so a
  failure closes only the node whose command failed.
- A `\Redis` whose socket is already closed is left alone, as its `close()`
  would connect again first, only to close.

A connection is closed for every user of the client: a pipeline or a `MULTI`
that other code holds open on it is dropped with it, as the closed socket
would drop it anyway.

**The rule after a failure.** What follows is what follows without the close,
on the new connection. On a single node, a pipeline holding a guarded write
that failed quickly is sent again once, one call at a time with new epochs; a
cluster pipeline that failed quickly is sent again one command at a time; an
`EVALSHA` that failed quickly is followed by an `EVAL`. Nothing is sent again
after a failure that waited out the read timeout, since a node that did not
answer would keep every second try waiting as long, nor where the batch never
went out (a connect that failed) or was not answered at all (a pipeline that
did not go out whole): no second try, no `EVAL`, and no unlink in place of a
tombstone. Symfony still makes its own retries item by item, as it does with
any adapter, and each of those waits for a node that does not answer. On
phpredis 6.3 a pipeline also waits the read timeout once per queued reply
inside `exec()`.

A read timeout of 0 is not "no limit": phpredis then waits PHP's
`default_socket_timeout`, a minute by default, and a failure counts from 95 %
of that. On phpredis 6.3, `RedisCluster::setOption(OPT_READ_TIMEOUT)` changes
the timeout the client reports, not the sockets it has open.

Relay's clients throw exceptions of their own, so the adapter closes none of
their connections. A pipeline of theirs that was not answered is treated as
any other: nothing is unlinked and no script body follows.

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
