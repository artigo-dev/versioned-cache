<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Adapter;

use Artigo\Cache\Exception\InvalidArgumentException;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\AbstractTagAwareAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Cache\Exception\CacheException;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Tag invalidation that costs the same whether three items match or three
 * million.
 *
 * The idea in three sentences. Invalidating a tag deletes no item: it adds a
 * rule such as "items tagged product-42 written before 12:00:00 are
 * outdated" to a list in Redis, a stream. Every worker keeps a copy of that
 * list in memory, and every item carries the id of the newest rule its writer
 * knew of, its watermark. A read checks the item's tags against the rules
 * newer than its watermark, and an item one of them names is a miss.
 *
 * An example with two workers. At 12:00:00.000 an editor changes product 42,
 * and worker A on server 1 calls invalidateTags(['product-42']). A appends
 * the rule in four Redis commands, however many items carry the tag. A's
 * copy already ends at the rule before, so A adds the new rule to it straight
 * from Redis's reply. A's next read of "product-page-42", written at 11:58
 * with the tag product-42, finds the rule newer than the item's watermark:
 * the item is outdated, so A deletes its data, reports a miss and computes
 * the page again. Worker B on server 2 holds a copy read at 11:59:59.500,
 * without the rule, so B still serves the old page. At 12:00:00.500 that
 * copy is a second old ($rulesCacheMs), and the next read on server 2
 * reloads it from Redis (one worker does that for the whole server). From
 * then on B finds the rule too, and treats the item as a miss.
 *
 * When something goes wrong:
 *
 * - If Redis does not answer, every worker keeps using the copy it has,
 *   however old, and one worker per server tries again a second after the
 *   last attempt. A worker with no copy treats every item as a miss and
 *   stores nothing it computes.
 * - If another worker on the server is already reloading, nobody waits for
 *   it. For the first second, a worker whose copy is recent enough keeps
 *   using it, and any other treats every item as a miss. After that second
 *   the reload is treated as if Redis were down.
 * - If an item is invalidated while a value for it is being computed, the
 *   write carries the watermark of the read made before the computation, so
 *   the next read finds it outdated. If the item is deleted meanwhile, the
 *   write is refused, as long as the delete is less than $guardWindowSeconds
 *   old. This is the invalidation guard.
 *
 * Read more in the README: "Tag invalidation that never gets slower", "The
 * rule set a read is judged against", "The shared rule set while it reloads"
 * and "The invalidation guard".
 *
 * Symfony's RedisTagAwareAdapter keeps a SET per tag. When the tag is
 * invalidated, it walks that SET and deletes every member, in one blocking
 * Lua loop as long as the number of matched items, during which the
 * single-threaded server does nothing else. This adapter never builds that
 * index, so there is nothing to walk. An outdated item is deleted by the
 * first read that finds it, or else expires with its TTL.
 *
 * The order of the rules comes from Redis, not from clocks: the node that
 * owns the stream gives each rule an id, and the ids only grow, so nothing
 * anywhere compares two clocks.
 *
 *     $redis = RedisAdapter::createConnection('redis://127.0.0.1');
 *     $pool = new VersionedRedisTagAwareAdapter($redis);
 *
 *     $pool->invalidateTags(['articles']);   // 4 commands, whatever matches
 *
 * An item is a hash written with HSETEX (Redis 8.0), which sets the fields
 * and their expiry in one command, so the data can never be stored without
 * the TTL that keeps it from outliving the rules. The key itself gets the TTL
 * of its longest-lived field, so a volatile-* eviction policy still sees it
 * as evictable.
 *
 * The rules stream, on the other hand, has no TTL, just like Symfony's tag
 * SETs. Under `volatile-*` or `noeviction` it is never evicted, and one of
 * those is the policy to run. Under `allkeys-*` it can be evicted, and reads
 * then fail safe: an item whose watermark is a rule the stream no longer
 * holds is a miss, never an outdated hit. The price is computing the pool
 * again once.
 *
 * Which watermark a write carries. A write that follows no read of this pool
 * gets the copy of the rules the way a read does, and carries that copy's
 * last rule id. The newest id in the stream is never read for a write. A
 * write that follows a read carries the watermark the read was checked at,
 * so a rule appended elsewhere while the value was computed still makes it
 * outdated. This pool's own invalidations move those watermarks forward, so
 * an item saved after the pool's own invalidation is fresh, as Symfony's tag
 * semantics require (TagAwareTestTrait tests it).
 *
 * The copy keeps only the newest rule per tag. Given a cache pool, the
 * workers of a server share it, so a fresh process (every request, under
 * PHP-FPM) takes over the shared copy instead of loading the whole stream
 * before its first hit (see SharedRules). APCu is the default where it is
 * enabled; pass false as $rulesCache to keep a copy per instance. The copy
 * has a limit, $rulesMaxTags. Past it, one floor rule replaces the oldest
 * rules, and an item older than the floor reads as a miss (see
 * InvalidationRules).
 *
 * An invalidation made on this server is never served stale on this server
 * while the stream can be read. If the invalidating worker's copy ends at the
 * rule the new one was appended after, the new rule is added to it from
 * Redis's reply. The copy is then shared for the other workers to take over:
 * at once for the first invalidation of a request, and when the request ends
 * (reset(), or the pool going away) for the later ones. Otherwise the copy
 * lacks the rule. A note beside the shared copy, the local-invalidation note,
 * records the newest invalidation made on this server. It keeps every worker
 * of the server from using a copy that lacks that invalidation until the copy
 * is reloaded. The note only moves forward (see SharedRules::written()).
 * During an outage, or while a reload hangs past a second, a copy that lacks
 * it is used like any other. Without a shared copy, another pool instance
 * sees the rule as one made elsewhere.
 *
 * A write that follows a read is guarded (see get() and MissRecords). Every
 * write gives the item a new epoch, a random token in its own field. A delete
 * leaves a tombstone: for $guardWindowSeconds the key holds a new epoch and
 * nothing else. The write that follows a miss is refused if the key holds an
 * epoch other than the one the miss saw, and stored if it holds that one or
 * none. One script does that check and the write, and it is the only script
 * on the write path. A write refused that way answers false. Together with
 * the read's watermark, this means a value computed from data that was
 * invalidated while it was computed is never stored as fresh, nor one
 * computed across a delete less than $guardWindowSeconds before its write.
 * A delete older than that has left no epoch to refuse it, so the window
 * has to be longer than the longest computation; a tag invalidation needs
 * no such bound. A value is not stored at all when nothing can tell
 * which rules it has seen: when it follows a read that had no copy of the
 * rules, when it is written while no copy is held, or when it follows a read
 * of the item that failed. get() still returns it. If get() computed it after
 * its own miss, the stored key stays as it is. Any other write (a plain one, a
 * forced recomputation, a save after a read) deletes the key, so the value it
 * was meant to replace is not served. $guardWindowSeconds = 0 switches the
 * guard off.
 *
 * A tag invalidation is soft unless invalidateTags() is told it is hard. With
 * $softGraceSeconds, an item that only soft rules made outdated, the newest
 * of them less than the grace ago, is not deleted when it is read. The read
 * is still a miss, but the old value is kept in this pool instance for
 * takeAgedItem(), which StaleCache's soft mode serves stale while it computes
 * a new one. The kept value goes with the next read, write or delete of the
 * key through this pool, and with a clear or a hard invalidation through it.
 * hasItem() and prune() leave such an item in Redis. A delete, a clear, a
 * hard rule, the floor and a lost stream are always hard. The age is counted
 * on the Redis server's clock, read once per process.
 *
 * On a cluster read from its replicas (failover=slaves), the item reads and
 * the rule fetches may come from a replica that lags. A lagging item looks
 * older, and a lagging rule arrives late by the lag, on top of
 * $rulesCacheMs. Neither can serve a value past an invalidation the copy
 * already holds. A replica that answers as if the stream had been lost is not
 * believed: the same range is asked of the primary before anything is
 * dropped.
 *
 * What it is worse at, and there is no getting around any of it:
 *
 * - **Memory comes back lazily.** An invalidated item holds its RAM until a
 *   read drops it or its TTL runs out. Reading items that were all
 *   invalidated costs roughly twice as much as reading live ones, and
 *   prune() is what takes back the items nobody reads again.
 * - **Invalidation made elsewhere becomes visible within `rulesCacheMs` plus
 *   a second**, because a copy of the rules is used for that long, and for
 *   up to a second more while another worker reloads it. A read that cannot
 *   promise that is a miss, unless Redis is down or a reload hangs (see
 *   "When something goes wrong" above). Pass 0 for exact reads, at the price
 *   of a reload for every read that can take the reload flag.
 * - **`$rulesRetentionSeconds` is the maximum item lifetime**, not only the
 *   default. An item must never outlive the rule that made it stale, or it
 *   would come back from the dead once that rule is trimmed away, so a longer
 *   TTL is capped and a warning is logged.
 * - **No tag index to inspect.** Nothing can be asked which items a tag
 *   names, the way SMEMBERS on a tag SET answers it for RedisTagAwareAdapter.
 *   Symfony's own interface never exposed that, so no API goes with it.
 */
final class VersionedRedisTagAwareAdapter extends AbstractTagAwareAdapter implements PruneableInterface, AgedItemsInterface
{
    /**
     * What Symfony puts between a namespace and a key. AbstractTagAwareAdapter
     * only exposes its own NS_SEPARATOR from 7.3 on, and this package supports
     * 6.4, so the value lives here rather than being borrowed.
     */
    private const NAMESPACE_SEPARATOR = ':';

    private const FIELD_VALUE = 'v';
    private const FIELD_TAGS = 't';
    private const FIELD_MARK = 'm';

    /**
     * The epoch field: a new random token on every write and every delete. A
     * write that follows a read is refused where the item holds a token other
     * than the one the read saw, so it is refused after a write or a delete
     * in between, for as long as that token lives: the guard window at least.
     */
    private const FIELD_EPOCH = 'e';

    /**
     * Recorded when reading the item failed, so we do not know which version
     * is stored. The write that follows is refused in PHP, before any script
     * runs (see doSave()): the guarded write takes a key that holds no epoch,
     * so it would store that write there.
     */
    private const EPOCH_UNKNOWN = '?';

    /**
     * Recorded when a read had no copy of the invalidation rules, so we cannot
     * tell whether the value computed after it is already outdated. That
     * value is not stored (see doSave()): stored anyway, it would either be
     * served after a rule that should have made it outdated, or, with every
     * rule applied to it, replace a stored value that may still be valid.
     */
    private const MARK_UNJUDGED = 'unjudged';

    /**
     * In milliseconds. While another worker reloads the rules, the others keep
     * using their copy for up to this long past $rulesCacheMs. A reload that
     * takes longer than this is treated as if Redis were down.
     */
    private const SLOW_RELOAD_MS = 1_000;

    /**
     * In milliseconds. While Redis is down, one worker of the server tries to
     * read the rules again this long after the last attempt ended.
     */
    private const RETRY_MS = 1_000;

    /**
     * Flags per key for a get() in progress. GET_UNLOCKED: the read missed
     * only because the rules could not be checked, so the value is computed
     * without the stampede lock (waiting for another worker would not help).
     * GET_OWN: the callback ran after a miss, so the write that follows fills
     * that miss instead of replacing a value.
     */
    private const GET_UNLOCKED = 1;
    private const GET_OWN = 2;

    /**
     * How many keys prune() lists, reads and checks at a time.
     */
    private const PRUNE_BATCH = 1_000;

    /**
     * How many rules one fetch from the stream asks for, so a load from the
     * start never holds more than one batch in memory beside the copy.
     */
    private const RULES_BATCH = 10_000;

    /**
     * Appends one rule, and keeps the stream from growing without limit. The
     * append trims as it goes (XADD ... MINID): a rule older than the longest
     * possible item lifetime, counted back from the rule before the new one,
     * can no longer match any living item. A rule that matches the whole pool
     * makes every older rule pointless, so the stream is cut back to it.
     *
     * Each rule records whether it started the stream. NOMKSTREAM refuses to
     * create a stream, so a nil reply means there was none, and the rule is
     * appended again as the first entry of a new stream. A reader that finds
     * an item whose watermark is older than such a first rule knows the
     * stream has lost rules the item once saw (see InvalidationRules).
     *
     * It returns the new id, the id of the rule before it, and whether it
     * started the stream. The caller adds the rule to its copy only when the
     * copy ends at that previous id. Otherwise it would skip a rule someone
     * else appended in between.
     *
     * The stream is the only record that an invalidation ever happened, so it
     * has no TTL. Under a volatile-* policy that keeps it out of eviction
     * altogether, where a TTL would make it a candidate like any item. PERSIST
     * runs every time, so the stream has no expiry however it was created.
     * Items cannot outlive the rules anyway: their TTL is capped to the
     * retention, and the trim above drops only rules no living item can match.
     *
     * It is a script because the trim needs the id XADD just generated. (The
     * other two scripts are the guarded write and the range fetch sent to a
     * primary.) It is sent as a full body: an invalidation is rare, and a
     * body does not depend on the server's script cache.
     */
    private const SCRIPT_INVALIDATE = <<<'LUA'
        local retention = tonumber(ARGV[2])
        local head = redis.call('XREVRANGE', KEYS[1], '+', '-', 'COUNT', 1)
        local previous = head[1] and head[1][1] or '0-0'
        local floor = math.max(0, tonumber(string.match(previous, '^(%d+)')) - retention)
        local opened = '0'
        local id = redis.call('XADD', KEYS[1], 'NOMKSTREAM', 'MINID', floor, '*', 't', ARGV[1], 'first', '0')

        if not id then
            opened = '1'
            id = redis.call('XADD', KEYS[1], '*', 't', ARGV[1], 'first', '1')
        end

        if ARGV[3] == '1' then
            redis.call('XTRIM', KEYS[1], 'MINID', id)
        end

        redis.call('PERSIST', KEYS[1])

        return {id, previous, opened}
        LUA;

    /**
     * The write that follows a read. It refuses where the key holds an epoch
     * other than the one the read saw (an empty one, if the item had none):
     * somebody deleted or wrote the item since. Otherwise it writes what a
     * plain write writes, with the read's watermark. A key that holds no
     * epoch at all takes the write: every delete and every write leaves one
     * for at least the guard window, so none came within the window before
     * this write, and an epoch that ran out by itself refuses nothing. No
     * single hash command can compare one field and set others, hence a
     * script. Its arguments are the value, the tags, the watermark, the epoch
     * the read saw, a new epoch, the TTL and the guard window (both in
     * milliseconds). It returns 1 when it wrote and 0 when it refused.
     *
     * For a short-lived item the epoch lives for the whole guard window, so a
     * write that follows an earlier miss is still refused after the item has
     * expired.
     */
    private const SCRIPT_GUARDED_WRITE = <<<'LUA'
        local seen = redis.call('HGET', KEYS[1], 'e')

        if seen and seen ~= ARGV[4] then
            return 0
        end

        if tonumber(ARGV[6]) >= tonumber(ARGV[7]) then
            redis.call('HSETEX', KEYS[1], 'PX', ARGV[6], 'FIELDS', 4, 'v', ARGV[1], 't', ARGV[2], 'm', ARGV[3], 'e', ARGV[5])
            redis.call('PEXPIRE', KEYS[1], ARGV[6])
        else
            redis.call('HSETEX', KEYS[1], 'PX', ARGV[6], 'FIELDS', 3, 'v', ARGV[1], 't', ARGV[2], 'm', ARGV[3])
            redis.call('HSETEX', KEYS[1], 'PX', ARGV[7], 'FIELDS', 1, 'e', ARGV[5])
            redis.call('PEXPIRE', KEYS[1], ARGV[7])
        end

        return 1
        LUA;

    /**
     * The same XRANGE a reload sends, wrapped in a script, because EVAL always
     * goes to the primary while a plain XRANGE may go to a replica.
     */
    private const SCRIPT_RANGE = <<<'LUA'
        return redis.call('XRANGE', KEYS[1], ARGV[1], '+', 'COUNT', ARGV[2])
        LUA;

    private MarshallerInterface $marshaller;
    private string $rulesKey;
    private string $poolNamespace;
    private ?InvalidationRules $rules = null;
    private ?float $rulesReadAt = null;

    /**
     * The newest invalidation this adapter made: when the append returned
     * (this server's clock), and the rule id Redis gave it. Kept here as well
     * as in the shared note, for a pool that shares no copy.
     */
    private ?float $writtenAtMs = null;
    private string $writtenId = InvalidationRules::NONE;

    /**
     * True once this request (the pool since its last reset()) has shared its
     * copy right inside an invalidateTags() call. Later invalidations in the
     * same request share only when the request ends.
     */
    private bool $sharedInRequest = false;

    /**
     * True when this worker's copy holds rules of its own that the shared copy
     * lacks. They are shared when the request ends, so a loop of
     * invalidations copies the whole set twice in total rather than once per
     * call.
     */
    private bool $unshared = false;

    /**
     * Used when there is no shared copy, or this worker reloads on its own:
     * whether its last attempt to fetch the rules failed (an outage), and when
     * it ended.
     */
    private bool $outage = false;
    private ?float $attemptEndedAtMs = null;

    /**
     * True when the store refused to record the reload flag the last time
     * this worker tried to take it. Until it can take the flag again, this
     * worker loads the rules on its own, and shares neither its copy nor its
     * outage state.
     */
    private bool $alone = false;

    /** @var array<string, true> the warnings this pool instance has logged */
    private array $warned = [];

    /**
     * Whether the connection may answer reads from a replica; null until it is
     * first needed, so a lazy connection is not opened by the constructor.
     */
    private ?bool $readsReplicas = null;

    private readonly int $guardWindowMs;

    /**
     * The reads this pool remembers, because a write may follow them (see
     * MissRecords).
     */
    private MissRecords $records;

    /**
     * The key of a get() whose read has not happened yet. The next fetch of a
     * single id is that read, and it is told to remember the item even on a
     * hit (see get()).
     */
    private ?string $pendingArm = null;

    /**
     * The keys of the get() calls in progress, each with the id its read
     * fetched, or null before that read.
     *
     * @var array<string, string|null>
     */
    private array $armed = [];

    /**
     * The ids of the get() calls in progress, each with its flags
     * (GET_UNLOCKED, GET_OWN).
     *
     * @var array<string, int>
     */
    private array $gets = [];

    private static ?string $guardedWriteSha = null;

    private readonly int $softGraceMs;

    /**
     * Values a read kept for takeAgedItem() because only soft rules made them
     * outdated, by id: the stored value, its tags, and how long ago the newest
     * of those rules was added. Oldest first.
     *
     * @var array<string, array{string, list<string>, int}>
     */
    private array $aged = [];

    /**
     * The Redis server's clock minus ours, in milliseconds, by rules key. Read
     * once per process, because the age of a soft invalidation is counted on
     * the server's clock.
     *
     * @var array<string, float>
     */
    private static array $clockOffsets = [];

    /** @var \Closure(string): string */
    private readonly \Closure $idOf;

    /**
     * The copy shared with the other workers of this server; null when the
     * caller passed false, or passed nothing and APCu is not available.
     */
    private ?SharedRules $sharedRules;

    /**
     * @param int                               $rulesRetentionSeconds how long rules - and therefore items - may live
     * @param int                               $rulesCacheMs          how long a copy of the rules is used before it is reloaded
     * @param CacheItemPoolInterface|false|null $rulesCache            where the workers of a server share the copy of the rules (see SharedRules): a pool, false for none, null for APCu where it is enabled
     * @param int                               $rulesMaxTags          how many rules the copy holds before one floor rule replaces the oldest (see InvalidationRules); 0 for no limit
     * @param int                               $guardWindowSeconds    how long a delete is remembered, to refuse a write computed across it; 0 switches the guard off
     * @param int                               $softGraceSeconds      how long an item that only soft invalidations reached is kept for takeAgedItem(), counted from the newest of them; 0 drops it at once
     */
    public function __construct(
        private readonly \Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis,
        string $namespace = '',
        int $defaultLifetime = 0,
        ?MarshallerInterface $marshaller = null,
        private readonly int $rulesRetentionSeconds = 2_592_000,
        private readonly int $rulesCacheMs = 1_000,
        CacheItemPoolInterface|false|null $rulesCache = null,
        private readonly int $rulesMaxTags = 15_000,
        int $guardWindowSeconds = 60,
        int $softGraceSeconds = 0,
    ) {
        // stream ids hold milliseconds, which a 32-bit integer cannot
        if (\PHP_INT_SIZE < 8) {
            throw new CacheException(\sprintf('"%s" needs a 64-bit PHP.', self::class));
        }

        if ($rulesRetentionSeconds < 1) {
            throw new InvalidArgumentException(\sprintf('Argument "$rulesRetentionSeconds" must be a positive number of seconds, %d given.', $rulesRetentionSeconds));
        }

        if ($rulesCacheMs < 0) {
            throw new InvalidArgumentException(\sprintf('Argument "$rulesCacheMs" must not be negative, %d given.', $rulesCacheMs));
        }

        if ($rulesMaxTags < 0) {
            throw new InvalidArgumentException(\sprintf('Argument "$rulesMaxTags" must not be negative, %d given.', $rulesMaxTags));
        }

        if ($guardWindowSeconds < 0) {
            throw new InvalidArgumentException(\sprintf('Argument "$guardWindowSeconds" must not be negative, %d given.', $guardWindowSeconds));
        }

        if ($softGraceSeconds < 0) {
            throw new InvalidArgumentException(\sprintf('Argument "$softGraceSeconds" must not be negative, %d given.', $softGraceSeconds));
        }

        self::rejectClientSideEncoding($redis);

        parent::__construct($namespace, $defaultLifetime);

        $this->marshaller = $marshaller ?? new DefaultMarshaller();
        $this->poolNamespace = '' === $namespace ? '' : $namespace.self::NAMESPACE_SEPARATOR;
        $this->rulesKey = $this->poolNamespace.'@rules';
        $this->sharedRules = SharedRules::of($rulesCache, $this->rulesKey, $redis);
        $this->guardWindowMs = $guardWindowSeconds * 1000;
        $this->records = new MissRecords();
        $this->softGraceMs = $softGraceSeconds * 1000;

        // turns a key into its id the way the parent does (its getId() is
        // private); bound to this instance, so __clone() binds it again
        $this->idOf = $this->bindIdOf();
    }

    /**
     * Symfony's get(): the value from the cache, or computed by $callback and
     * saved. This pool adds three things to it.
     *
     * - The read this get() makes remembers the item even on a hit, so a hit
     *   the callback recomputes anyway ($beta = INF, or an early expiration)
     *   is guarded like a miss. That read is always a fetch of the key's id
     *   alone (getItem()), which is where the id becomes known. When the call
     *   ends, by return or by throw, the remembered read is forgotten.
     * - When the callback ran after a miss, the write that follows is marked
     *   as this pool's own computation (see doSave()).
     * - If the read missed only because the rules could not be checked (this
     *   worker has no usable copy while another worker reloads them, or no
     *   copy at all while Redis is down), the value is computed without the
     *   stampede lock. Waiting would not help: a waiting worker's own read
     *   would miss the same way, and with no copy nothing computed is stored
     *   for it to find.
     *
     * A get() of a key this pool is already computing, called from inside its
     * callback, changes none of this.
     *
     * @param array<mixed>|null $metadata
     */
    public function get(string $key, callable $callback, ?float $beta = null, ?array &$metadata = null): mixed
    {
        $id = ($this->idOf)($key);

        // a get() of the key this pool is already computing, from inside its
        // callback: it sets and clears nothing, so the outer get() keeps its
        // flags and its remembered read
        if (isset($this->gets[$id])) {
            return parent::get($key, $callback, $beta, $metadata);
        }

        // this key now has a get() in progress, with no flags yet. With the
        // guard on, its read is told to remember the item even on a hit.
        // $pending is an outer get() whose read is still to come, put back at
        // the end
        $this->gets[$id] = 0;
        $armed = 0 < $this->guardWindowMs;
        $pending = $this->pendingArm;

        if ($armed) {
            $this->pendingArm = $key;
            $this->armed[$key] = null;
        }

        // the callback, wrapped: when it ran on a miss, and the caller did not
        // force a recomputation with $beta = INF, the write that follows only
        // fills that miss (GET_OWN)
        $computed = function (ItemInterface $item, bool &$save) use ($callback, $beta, $id): mixed {
            $value = $callback($item, $save);

            if (\INF !== $beta && !$item->isHit() && isset($this->gets[$id])) {
                $this->gets[$id] |= self::GET_OWN;
            }

            return $value;
        };

        // skip the stampede lock for a key whose read missed only because the
        // rules could not be checked. That is known only once the read is
        // made: a copy that looked recent may turn out to lack an invalidation
        // made on this server, or a newer shared copy may be taken over
        $lock = $this->setCallbackWrapper(null);
        $this->setCallbackWrapper($this->unlockedWhileReloading($lock));

        try {
            return parent::get($key, $computed, $beta, $metadata);
        } finally {
            // whether it returned or threw: the usual lock again, and this
            // get() is no longer in progress
            $this->setCallbackWrapper($lock);

            unset($this->gets[$id]);

            if ($armed) {
                // forget the read it remembered: its write is done, or will
                // not come
                if (isset($this->armed[$key])) {
                    $this->records->drop($this->armed[$key]);
                }

                unset($this->armed[$key]);

                // the read never happened: hand the slot back to an outer get()
                if ($key === $this->pendingArm) {
                    $this->pendingArm = $pending;
                }
            }
        }
    }

    /**
     * The callback wrapper a get() uses. A key whose read missed only because
     * the rules could not be checked (GET_UNLOCKED) is computed at once; any
     * other key goes through $lock, the usual stampede lock.
     */
    private function unlockedWhileReloading(callable $lock): \Closure
    {
        return function (callable $callback, ItemInterface $item, bool &$save, CacheInterface $pool, ?\Closure $setMetadata = null, ?LoggerInterface $logger = null, ?float $beta = null) use ($lock): mixed {
            if (0 !== (self::GET_UNLOCKED & ($this->gets[($this->idOf)($item->getKey())] ?? 0))) {
                return $callback($item, $save);
            }

            return $lock($callback, $item, $save, $pool, $setMetadata, $logger, $beta);
        };
    }

    /**
     * Is the write of $id this pool's own computation after a miss: a get()
     * of it whose callback ran on a miss, without a forced recomputation?
     */
    private function ownComputation(string $id): bool
    {
        return 0 !== (self::GET_OWN & ($this->gets[$id] ?? 0));
    }

    public function __clone()
    {
        // the remembered reads belong to the instance that made them
        $this->records = new MissRecords();
        $this->armed = [];
        $this->gets = [];
        $this->pendingArm = null;
        $this->aged = [];
        $this->idOf = $this->bindIdOf();

        // rules left to share are for the original to share
        $this->unshared = false;
        $this->sharedInRequest = false;
    }

    /**
     * Shares the copy this request's later invalidations went into, then
     * commits the deferred saves.
     */
    public function __destruct()
    {
        $this->shareUnshared();

        parent::__destruct();
    }

    /**
     * Appends one rule to the stream, and that is all: the reason this adapter
     * exists.
     *
     * The tags are used as written, not turned into index ids: there is no
     * index.
     *
     * A rule is soft unless $hard says otherwise: a pool with a grace may keep
     * an item a soft rule reached, for takeAgedItem(). A soft rule after a
     * hard one for the same tag does not weaken it. Without a grace, soft and
     * hard rules work the same.
     */
    public function invalidateTags(array $tags, bool $hard = false): bool
    {
        if ([] === $tags) {
            return false;
        }

        // a hard rule may reach any value kept for takeAgedItem(). Those only
        // live for this request, so dropping them all costs less than checking
        // each
        if ($hard) {
            $this->aged = [];
        }

        return $this->addRule(InvalidationRules::strings(array_unique(InvalidationRules::strings($tags))), null, $hard);
    }

    /**
     * Hands out, once, the value a read of $key kept because only soft rules
     * had made it outdated: the value, its tags, and how long ago the newest
     * of those rules was added. Null when there is none, or it does not
     * unmarshal. The next read, write or delete of $key through this pool
     * drops it, and a clear, a hard invalidation or a reset drops every kept
     * value (see AgedItemsInterface).
     *
     * @experimental
     *
     * @return array{value: mixed, tags: list<string>, ageMs: int}|null
     */
    public function takeAgedItem(string $key): ?array
    {
        $id = ($this->idOf)($key);
        $aged = $this->aged[$id] ?? null;
        unset($this->aged[$id]);

        if (null === $aged) {
            return null;
        }

        [$value, $tags, $ageMs] = $aged;

        try {
            $value = $this->marshaller->unmarshall($value);
        } catch (\Exception) {
            return null;
        }

        if (!\is_array($value) || !\array_key_exists('value', $value)) {
            return null;
        }

        return ['value' => $value['value'], 'tags' => $tags, 'ageMs' => $ageMs];
    }

    public function reset(): void
    {
        parent::reset();

        // the copy of the rules and its read time are not request state: they
        // stay, since they are only ever out of date for a bounded time. The
        // copy is shared now if this request's later invalidations went into
        // it. The remembered reads and kept values belong to the request and go
        $this->shareUnshared();
        $this->sharedInRequest = false;
        $this->records->clear();
        $this->armed = [];
        $this->gets = [];
        $this->pendingArm = null;
        $this->aged = [];
    }

    /**
     * Unlinks the items a rule invalidated that nobody has read since.
     *
     * Invalidating costs four commands here because it deletes nothing: a
     * stale item is dropped by the reader that finds it, and one nobody asks
     * for again waits for its TTL. That is the bargain, and this is the way
     * out of the single case where it is a poor one: a tag matching a great
     * many items, none of them read afterwards.
     *
     * The walk checks each item against this worker's copy of the rules
     * exactly as a read does, and unlinks what a read would drop. An item that
     * only soft rules reached, within the grace, stays, as a read keeps it.
     * There is no index to consult and none to repair: this gives memory back,
     * it does not make the pool any more correct, and a pool that is never
     * pruned is correct all the same. Symfony's cache:pool:prune command
     * drives it.
     *
     * The rules are read once, at the start. A rule appended during the walk
     * is not applied this time, and an item written during it carries a
     * watermark newer than any rule in that copy, so it cannot be found
     * outdated. Either way the walk only keeps an item that could have gone,
     * which is harmless.
     *
     * Between reading an item's watermark and unlinking it, a writer may put
     * a fresh item in its place, which is then unlinked too: one extra miss.
     * A delete in that instant loses its tombstone the same way. Either way
     * the epoch they left is gone, so a computation that read the item before
     * has its write stored, as after a delete older than the guard window:
     * the bound the guard has anyway, which a walk from a cron meets rarely.
     *
     * A pool with no namespace has no keys of its own to name, so the walk
     * covers the whole database and checks every hash in it, as Symfony's own
     * prune does. Namespace a pool that shares its database.
     *
     * The batches are pipelined where the connection allows it, which a
     * cluster only does where phpredis has cluster pipelines: elsewhere a
     * prune costs one round trip per item rather than one per thousand. It is
     * a job for a cron either way.
     */
    public function prune(): bool
    {
        // with no copy of the rules, or one this worker may not use while
        // another worker reloads, nothing can be decided: a read treats every
        // item as a miss and deletes nothing, and so does prune()
        [$rules, $unlocked] = $this->rules();

        if (null === $rules || $unlocked) {
            return false;
        }

        $ok = true;
        $batch = [];

        try {
            foreach ($this->pruneKeys() as $key) {
                $batch[] = $key;

                if (self::PRUNE_BATCH <= \count($batch)) {
                    $ok = $this->pruneBatch($rules, $batch) && $ok;
                    $batch = [];
                }
            }
        } catch (\Exception $e) {
            CacheItem::log($this->logger, 'Failed to walk the pool: '.$e->getMessage(), ['exception' => $e, 'cache-adapter' => get_debug_type($this)]);

            $ok = false;
        }

        if ([] !== $batch) {
            $ok = $this->pruneBatch($rules, $batch) && $ok;
        }

        return $ok;
    }

    /**
     * Writes the items, each in one of three ways.
     *
     * - After a read of this pool that had a copy of the rules: the guarded
     *   write. It is refused if the item holds an epoch other than the one
     *   the read saw, stored if it holds that one or none, and it carries the
     *   read's watermark.
     * - After no read of this pool: a plain write, which carries the last
     *   rule id of this worker's copy of the rules as its watermark.
     * - After a read that had no copy of the rules, or that failed to read the
     *   item, or with no copy at all: refused, because nothing can tell which
     *   rules the value has seen (see MARK_UNJUDGED).
     *
     * @param array<string, array{value: mixed, tags?: list<string>, meta?: string}> $values
     * @param array<string, list<string>>                                            $addTagData
     * @param array<string, list<string>>                                            $removeTagData
     *
     * @return list<string>
     */
    protected function doSave(array $values, int $lifetime, array $addTagData = [], array $removeTagData = []): array
    {
        // a value kept for takeAgedItem() is older than the value written over it
        $this->forgetAged(array_keys($values));

        // the tags go into their own field, not into the marshalled value
        $payloads = [];
        $tags = [];

        foreach ($values as $id => $value) {
            $tags[$id] = InvalidationRules::strings($value['tags'] ?? []);
            unset($value['tags']);
            $payloads[$id] = $value;
        }

        // nothing could be marshalled: report the failures
        if (!$marshalled = $this->marshaller->marshall($payloads, $failed)) {
            return InvalidationRules::strings($failed ?? []);
        }

        // warn once per pool instance about a lifetime the retention cuts short
        if ($lifetime > $this->rulesRetentionSeconds) {
            $this->warnOnce('ttl', 'A lifetime of {lifetime}s is above rulesRetentionSeconds ({retention}s) and was capped to it; raise the retention to keep items longer.', ['lifetime' => $lifetime, 'retention' => $this->rulesRetentionSeconds]);
        }

        // an item must never outlive the rules: no lifetime, or one beyond the
        // retention, becomes the retention
        $ttl = ($lifetime <= 0 || $lifetime > $this->rulesRetentionSeconds ? $this->rulesRetentionSeconds : $lifetime) * 1000;

        $calls = [];
        $first = [];
        $guarded = [];
        $dropped = [];
        $unlink = [];
        $mark = null;
        $markRead = false;
        $nowMs = microtime(true) * 1000;

        foreach ($marshalled as $id => $value) {
            $id = (string) $id;

            // the marshaller could not encode it
            if (!\is_string($value)) {
                $failed[] = $id;

                continue;
            }

            $encodedTags = json_encode($tags[$id], \JSON_THROW_ON_ERROR);

            // the read of this key the pool remembers, if any (only with the
            // guard on)
            $record = 0 < $this->guardWindowMs ? $this->records->of($id) : null;
            $refusal = null;

            if (null !== $record) {
                [$recordMark, $seen] = $record;

                // refused here, not by the script: the script takes a key
                // without an epoch, and would store this write there
                if (self::EPOCH_UNKNOWN === $seen) {
                    $refusal = 'the read before it could not learn the stored epoch';
                } elseif (self::MARK_UNJUDGED === $recordMark) {
                    $refusal = 'no invalidation rules were held for the read before it';
                } else {
                    // the usual write after a read of this pool: the guarded
                    // write, refused where the item holds an epoch other than
                    // the one the read saw, and carrying the read's watermark
                    $first[$id] = \count($calls);
                    $guarded[$id] = [$id, $value, $encodedTags, $recordMark, $seen, self::epoch(), (string) $ttl, (string) $this->guardWindowMs];
                    $calls[] = ['evalSha', self::guardedWriteSha(), $guarded[$id], 1];

                    continue;
                }
            } else {
                // a write that follows no read of this pool. It gets the copy
                // of the rules the way a read does, once for the whole batch,
                // and carries that copy's last rule id as its watermark. The
                // newest id in the stream is never read for a write. An older
                // copy gives an older watermark, which can only make the item
                // be found outdated sooner, never serve it past a rule
                if (!$markRead) {
                    $mark = $this->rules()[0]?->last();
                    $markRead = true;
                }

                // no copy of the rules at all: nothing can tell which rules
                // the value has seen
                if (null === $mark) {
                    $refusal = 'no invalidation rules are held';
                }
            }

            if (null !== $refusal) {
                // a read that had no copy of the rules, or could not read the
                // item's epoch, refuses the write that follows it. Once that
                // read is older than the guard window, it refuses this one
                // write and is then forgotten, so the write after that is a
                // normal one
                if (null !== $record && $nowMs - $record[2] > $this->guardWindowMs) {
                    $this->records->drop($id);
                }

                // get() computed this after its own miss: what Redis holds may
                // still be valid, so it stays
                if ($this->ownComputation($id)) {
                    $failed[] = $id;
                    $this->logger?->info('Refused to save "{key}": {reason}.', ['key' => $id, 'reason' => $refusal, 'cache-adapter' => get_debug_type($this)]);

                    continue;
                }

                if (0 === $this->guardWindowMs) {
                    // the guard is off, so there is no window for a tombstone
                    // to live in: the key is deleted
                    $failed[] = $id;
                    $unlink[] = $id;
                    $this->logger?->info('Refused to save "{key}": {reason}, so the stored value was dropped instead.', ['key' => $id, 'reason' => $refusal, 'cache-adapter' => get_debug_type($this)]);

                    continue;
                }

                // the guard is on: a tombstone takes the stored value's place
                $first[$id] = \count($calls);
                $dropped[$id] = $refusal;

                foreach ($this->tombstone($id) as $call) {
                    $calls[] = $call;
                }

                continue;
            }

            // a plain write, with the copy's last rule id as its watermark
            $first[$id] = \count($calls);

            foreach ($this->plainWrite($id, $value, $encodedTags, $mark, $ttl) as $call) {
                $calls[] = $call;
            }
        }

        // every write of the batch, in one round trip where the connection
        // allows it
        $results = $this->pipeline($calls);

        // a server that does not have the guarded-write script cached (after a
        // restart, a failover or SCRIPT FLUSH) answers false: the write is
        // sent again with the script's full body
        $again = [];

        foreach ($guarded as $id => $arguments) {
            if (!\is_int($results[$first[$id]] ?? null)) {
                $again[$id] = ['eval', self::SCRIPT_GUARDED_WRITE, $arguments, 1];
            }
        }

        if ([] !== $again) {
            foreach (array_combine(array_keys($again), $this->pipeline(array_values($again))) as $id => $result) {
                $results[$first[$id]] = $result;
            }
        }

        // Relay does not drop its in-process copy of a key a script writes. So
        // a miss another connection of this process read before would still
        // be served from that cache, until this connection takes in the
        // server's invalidation message. dispatchEvents() does that now,
        // without a round trip
        if ([] !== $guarded && ($this->redis instanceof \Relay\Relay || $this->redis instanceof \Relay\Cluster)) {
            try {
                $this->redis->dispatchEvents();
            } catch (\Exception) {
                // the writes are already on the server; the next command takes
                // in the message
            }
        }

        // one answer per item
        foreach ($first as $id => $index) {
            $result = $results[$index] ?? null;

            // refused, and a tombstone written instead. The remembered read
            // stays (unless it was past the guard window), so saving the item
            // again is refused too
            if (isset($dropped[$id])) {
                $failed[] = $id;
                $this->logger?->info('Refused to save "{key}": {reason}, so the stored value was dropped instead.', ['key' => $id, 'reason' => $dropped[$id], 'cache-adapter' => get_debug_type($this)]);

                // a value of another type under the key refuses the
                // tombstone: the key is deleted instead
                if (1 !== $result) {
                    $this->unlink([$id]);
                }

                continue;
            }

            // a plain write failed unless the server answered 1
            if (!isset($guarded[$id])) {
                if (1 !== $result) {
                    $failed[] = $id;
                }

                continue;
            }

            if (1 === $result) {
                // stored: the read is used up. A later write of this key from
                // this pool comes after this one, so it is a plain write
                $this->records->drop($id);

                continue;
            }

            // refused (0) or failed: the remembered read stays, so saving the
            // item again is checked against the same read instead of being
            // stored as a plain write
            $failed[] = $id;

            if (0 === $result) {
                $this->logger?->info('Refused to save "{key}": it was deleted or written by somebody else while it was computed.', ['key' => $id, 'cache-adapter' => get_debug_type($this)]);
            }
        }

        // the keys of refused writes while the guard is off
        if ([] !== $unlink) {
            $this->unlink($unlink);
        }

        return InvalidationRules::strings($failed ?? []);
    }

    /**
     * @param list<string> $ids
     *
     * @return iterable<string, array<string, mixed>>
     */
    protected function doFetch(array $ids): iterable
    {
        if ([] === $ids) {
            return;
        }

        // a value kept for takeAgedItem() is what the last read found. This
        // read decides again whether to keep one, and keeps nothing if the
        // item is now a tombstone or a hard rule reached it
        $this->forgetAged($ids);

        // this is the read of a get() in progress: note its id, so the item is
        // remembered even on a hit (see get()). That happens even if the item
        // cannot be checked, because a request left pending would apply to the
        // next read of another key inside the callback
        if (null !== $this->pendingArm && 1 === \count($ids)) {
            $this->armed[$this->pendingArm] = $ids[0];
            $this->pendingArm = null;
        }

        // a batch is remembered whole, however large (see MissRecords)
        if (0 < $this->guardWindowMs) {
            $this->records->reserve(\count($ids));
        }

        [$rules, $unlocked] = $this->rules();

        // tell each get() of these keys whether its read misses only because
        // the rules cannot be checked, so it computes without the stampede
        // lock (see get())
        if ([] !== $this->gets) {
            foreach ($ids as $id) {
                if (isset($this->gets[$id])) {
                    $this->gets[$id] = $unlocked ? $this->gets[$id] | self::GET_UNLOCKED : $this->gets[$id] & ~self::GET_UNLOCKED;
                }
            }
        }

        // with no copy of the rules, a fresh item cannot be told from an
        // outdated one: every item is a miss for now, nothing is deleted, and
        // the write that follows is refused (see MARK_UNJUDGED)
        if (null === $rules) {
            foreach ($ids as $id) {
                $this->remember($id, self::MARK_UNJUDGED, '');
            }

            return;
        }

        // when this worker's copy may not be used while another worker
        // reloads (see rules()), every item is a miss: only its epoch is read,
        // for the write that follows
        $fields = $unlocked ? [self::FIELD_EPOCH] : [self::FIELD_VALUE, self::FIELD_TAGS, self::FIELD_MARK, self::FIELD_EPOCH];
        $calls = [];

        foreach ($ids as $id) {
            $calls[] = ['hMGet', $id, $fields];
        }

        $held = $rules->last();

        try {
            $rows = $this->pipeline($calls);
        } catch (\Exception $e) {
            // a read that throws (a timeout, which phpredis raises from the
            // pipeline) did not learn the epochs: the write after it must not
            // be stored
            foreach ($ids as $id) {
                $this->remember($id, $held, self::EPOCH_UNKNOWN);
            }

            throw $e;
        }

        if ($unlocked) {
            // what is computed gets the last rule id of this worker's copy as
            // its watermark, so every rule added since still applies to it
            foreach ($ids as $index => $id) {
                $this->remember($id, $held, self::epochOf($rows[$index] ?? null));
            }

            return;
        }

        $stale = [];

        foreach ($ids as $index => $id) {
            $row = $rows[$index] ?? null;
            $epoch = self::epochOf($row);

            // no item, or a tombstone: a miss, and the computation that
            // follows is guarded
            if (!\is_array($row) || !\is_string($row[self::FIELD_VALUE] ?? null) || null === $tagged = self::tagsAndMark($row)) {
                $this->remember($id, $held, $epoch);

                continue;
            }

            [$tags, $mark] = $tagged;

            // a rule newer than the item's watermark names it: outdated, a
            // miss, and the computation that follows is guarded
            if ($rules->invalidates($id, $tags, $mark)) {
                $this->remember($id, $held, $epoch);

                // if only soft rules reached it, within the grace, it is kept
                // for takeAgedItem() instead of deleted: a miss either way
                if (null !== $ageMs = $this->softAge($rules, $id, $tags, $mark)) {
                    $this->keepAged($id, $row[self::FIELD_VALUE], $tags, $ageMs);
                } else {
                    $stale[] = $id;
                }

                continue;
            }

            try {
                $value = $this->marshaller->unmarshall($row[self::FIELD_VALUE]);
            } catch (\Exception) {
                $value = null;
            }

            // a value that does not unmarshal: deleted, a miss, and the
            // computation that follows is guarded
            if (!\is_array($value) || !\array_key_exists('value', $value)) {
                $stale[] = $id;
                $this->remember($id, $held, $epoch);

                continue;
            }

            // a hit forgets an earlier remembered read of the key, unless a
            // get() is reading it and may recompute it anyway
            if (\in_array($id, $this->armed, true)) {
                $this->remember($id, $held, $epoch);
            } else {
                $this->records->drop($id);
            }

            $item = ['value' => $value['value'], 'tags' => $tags];

            if (\array_key_exists('meta', $value)) {
                $item['meta'] = $value['meta'];
            }

            yield $id => $item;
        }

        if ([] !== $stale) {
            // the lazy half of the bargain: an outdated item costs the reader
            // that finds it one delete, and nobody else anything at all
            $this->dropStale($stale);
        }
    }

    /**
     * Reads the tags and the watermark only: the answer needs no value. An
     * item that only soft rules reached, within the grace, is a miss here
     * too, and stays in Redis for the read that may keep it (see doFetch()).
     */
    protected function doHave(string $id): bool
    {
        // this is a read of the key, so a value an earlier read kept for
        // takeAgedItem() goes
        $this->forgetAged([$id]);

        [$rules, $unlocked] = $this->rules();

        // no usable copy of the rules: a miss
        if (null === $rules || $unlocked) {
            return false;
        }

        if (null === $tagged = self::tagsAndMark($this->redis->hMGet($id, [self::FIELD_TAGS, self::FIELD_MARK]))) {
            return false;
        }

        [$tags, $mark] = $tagged;

        if ($rules->invalidates($id, $tags, $mark)) {
            if (null === $this->softAge($rules, $id, $tags, $mark)) {
                $this->dropStale([$id]);
            }

            return false;
        }

        return true;
    }

    /**
     * Deletes items of this pool. This pool made the delete itself, so its
     * remembered reads of those items are forgotten, and its next write of
     * them is a plain one.
     *
     * With the guard on, each item becomes a tombstone for the guard window:
     * a hash holding only a new epoch. A write computed from what was there
     * before is then refused. Three commands, in this order. The epoch first,
     * so a guarded write that runs between any two of them sees a new epoch
     * and is refused. The data next, so a plain write stored before it is
     * removed (it came first), and one stored after it stays. The key's TTL
     * last, only ever raised (GT), so the key lives at least as long as every
     * field in it: the tombstone's epoch, or the fields of a write stored
     * after the data was removed. Redis removes the key once its last field
     * has expired. A value of another type under the key makes the first
     * command fail, and is unlinked instead.
     *
     * @param list<string> $ids
     */
    protected function doDelete(array $ids): bool
    {
        if ([] === $ids) {
            return true;
        }

        $this->records->drop(...$ids);

        // a deleted value is never served stale
        $this->forgetAged($ids);

        if (0 === $this->guardWindowMs) {
            return $this->unlink($ids);
        }

        $calls = [];

        foreach ($ids as $id) {
            foreach ($this->tombstone($id) as $call) {
                $calls[] = $call;
            }
        }

        try {
            $results = $this->pipeline($calls);
        } catch (\Exception $e) {
            CacheItem::log($this->logger, 'Failed to delete items: '.$e->getMessage(), ['exception' => $e, 'cache-adapter' => get_debug_type($this)]);

            return false;
        }

        $raw = [];

        foreach ($ids as $index => $id) {
            if (1 !== ($results[3 * $index] ?? null)) {
                $raw[] = $id;
            }
        }

        return [] === $raw || $this->unlink($raw);
    }

    /**
     * The three commands of a tombstone at $id (see doDelete()). The first
     * answers 1 when the epoch was set.
     *
     * @return list<array{0: string, ...}>
     */
    private function tombstone(string $id): array
    {
        if ($this->writesTyped()) {
            return [
                ['hSetEx', $id, [self::FIELD_EPOCH => self::epoch()], ['EX' => intdiv($this->guardWindowMs, 1000)]],
                ['hDel', $id, self::FIELD_VALUE, self::FIELD_TAGS, self::FIELD_MARK],
                ['expire', $id, intdiv($this->guardWindowMs, 1000), 'GT'],
            ];
        }

        $window = (string) $this->guardWindowMs;

        return [
            $this->command('HSETEX', $id, 'PX', $window, 'FIELDS', '1', self::FIELD_EPOCH, self::epoch()),
            $this->command('HDEL', $id, self::FIELD_VALUE, self::FIELD_TAGS, self::FIELD_MARK),
            $this->command('PEXPIRE', $id, $window, 'GT'),
        ];
    }

    /**
     * There is no tag index to clean up when an item goes, so nothing is
     * yielded and the parent skips the relation bookkeeping entirely. The
     * default reads every item first to learn its tags; we can just delete.
     *
     * @param list<string> $ids
     *
     * @return iterable<string, list<string>>
     */
    protected function doDeleteYieldTags(array $ids): iterable
    {
        if (!$this->doDelete($ids)) {
            // tell the caller, which retries the ids one by one
            throw new CacheException('Failed to delete the items.');
        }

        yield from [];
    }

    /**
     * @param array<string, list<string>> $tagData
     */
    protected function doDeleteTagRelations(array $tagData): bool
    {
        return true;
    }

    /**
     * @param list<string> $tagIds
     */
    protected function doInvalidate(array $tagIds): bool
    {
        return $this->addRule($tagIds, null, false);
    }

    /**
     * Symfony hands a key prefix here, not always the whole pool: clearing
     * "foo" must leave "barfoo" where it is. A prefix rule says so in one
     * appended entry, where an eager adapter has to walk the keyspace.
     */
    protected function doClear(string $namespace): bool
    {
        // the values kept for takeAgedItem() go: a cleared value is never
        // served stale
        $this->aged = [];

        return $this->addRule([], $namespace, true);
    }

    /**
     * Reads the tags and the watermark of a batch of keys, and unlinks those
     * the rules make outdated, except those that only soft rules reached
     * within the grace. The rules key is skipped: it is the stream of
     * invalidations itself, not an item they can reach.
     *
     * @param list<string> $keys
     */
    private function pruneBatch(InvalidationRules $rules, array $keys): bool
    {
        $fields = [self::FIELD_TAGS, self::FIELD_MARK];
        $calls = [];
        $ids = [];

        foreach ($keys as $key) {
            if ($this->rulesKey === $key) {
                continue;
            }

            $ids[] = $key;
            $calls[] = ['hMGet', $key, $fields];
        }

        if ([] === $ids) {
            return true;
        }

        $rows = $this->pipeline($calls);
        $stale = [];

        foreach ($ids as $index => $id) {
            // not one of this pool's items, or gone between the walk listing
            // it and this read
            if (null === $tagged = self::tagsAndMark($rows[$index] ?? null)) {
                continue;
            }

            [$tags, $mark] = $tagged;

            // what only soft rules reached stays for the grace, as a read
            // keeps it for takeAgedItem()
            if ($rules->invalidates($id, $tags, $mark) && null === $this->softAge($rules, $id, $tags, $mark)) {
                $stale[] = $id;
            }
        }

        // an unlink is enough, no tombstone: no data changed, and the write of
        // a computation that read the item carries its read's watermark, which
        // the rules still judge. A delete or a write of the key in the instant
        // since the read above loses its epoch here too, and a computation
        // across it is then stored, as after a delete older than the guard
        // window. prune() runs from a cron, so that race is rare
        return [] === $stale || $this->unlink($stale);
    }

    /**
     * Every key of this pool, node by node, named the way the pool names them:
     * a client-side prefix goes on the pattern where the client does not put
     * it there itself, and comes off whatever the server answers with.
     *
     * @return iterable<string>
     */
    private function pruneKeys(): iterable
    {
        [$patternPrefix, $keyPrefix] = self::scanPrefixes($this->redis);
        $pattern = $patternPrefix.$this->poolNamespace.'*';
        $strip = \strlen($keyPrefix);

        // a cluster is walked master by master, through the one client that
        // also routes the reads and the unlinks afterwards
        if ($this->redis instanceof \RedisCluster || $this->redis instanceof \Relay\Cluster) {
            foreach ($this->redis->_masters() as $master) {
                $address = self::address($master) ?? throw new CacheException(\sprintf('The cluster named a master as %s, which cannot be scanned.', get_debug_type($master)));
                $cursor = null;

                do {
                    $keys = self::scanned($this->redis->scan($cursor, $address, $pattern, self::PRUNE_BATCH), $cursor);

                    foreach ($keys as $key) {
                        yield $strip > 0 ? substr($key, $strip) : $key;
                    }
                } while ($cursor);
            }

            return;
        }

        foreach ($this->scanNodes() as $node) {
            $cursor = null;

            do {
                $keys = self::scanned($node->scan($cursor, $pattern, self::PRUNE_BATCH), $cursor);

                foreach ($keys as $key) {
                    yield $strip > 0 ? substr($key, $strip) : $key;
                }
            } while ($cursor);
        }
    }

    /**
     * The connections a walk visits one at a time: one on its own, or every
     * host of an array of them. A cluster is not among them: it is walked
     * through its own client, which is the only thing that can route to a
     * master.
     *
     * @return list<\Redis|\Relay\Relay>
     */
    private function scanNodes(): array
    {
        if ($this->redis instanceof \Redis || $this->redis instanceof \Relay\Relay) {
            return [$this->redis];
        }

        if (!$this->redis instanceof \RedisArray) {
            return [];
        }

        $nodes = [];

        $hosts = $this->redis->_hosts();

        foreach (\is_array($hosts) ? $hosts : [] as $host) {
            if (!\is_string($host)) {
                continue;
            }

            $node = $this->redis->_instance($host);

            if ($node instanceof \Redis) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    /**
     * A SCAN answers a batch of keys and moves the cursor it was given, except
     * on a cluster, where it answers [cursor, keys] and leaves the cursor
     * alone.
     *
     * @return list<string>
     */
    private static function scanned(mixed $answer, mixed &$cursor): array
    {
        if (\is_array($answer) && isset($answer[1]) && \is_array($answer[1])) {
            $cursor = $answer[0];
            $answer = $answer[1];
        }

        $keys = [];

        foreach (\is_array($answer) ? $answer : [] as $key) {
            if (\is_string($key)) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * A master as the client that has to be told which one to scan takes it:
     * [host, port], as _masters() answers. phpredis and Relay alike hash a
     * "host:port" string as a key, prefix included, and scan whichever master
     * owns that slot.
     *
     * @return array{string, int}|null
     */
    private static function address(mixed $master): ?array
    {
        if (!\is_array($master) || !\is_string($master[0] ?? null) || !\is_int($master[1] ?? null)) {
            return null;
        }

        return [$master[0], $master[1]];
    }

    /**
     * A client-side prefix is on every key the server answers with, but only
     * some clients put it on the pattern for us.
     *
     * @return array{0: string, 1: string} what to prepend to the pattern, and what to strip from the keys
     */
    private static function scanPrefixes(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis): array
    {
        $isRelay = $redis instanceof \Relay\Relay || $redis instanceof \Relay\Cluster;
        $prefix = $redis->getOption($isRelay ? \Relay\Relay::OPT_PREFIX : \Redis::OPT_PREFIX);

        // an array of connections answers once per node; they share the option
        if (\is_array($prefix)) {
            $prefix = reset($prefix);
        }

        if (!\is_string($prefix) || '' === $prefix) {
            return ['', ''];
        }

        $scan = $redis->getOption($isRelay ? \Relay\Relay::OPT_SCAN : \Redis::OPT_SCAN);

        // and so does this one, which the walk applies to every node alike
        if (\is_array($scan)) {
            $scan = reset($scan);
        }

        $scan = \is_int($scan) || \is_string($scan) ? (int) $scan : 0;

        $prefixesItself = $isRelay
            ? (bool) (\Relay\Relay::SCAN_PREFIX & $scan)
            : \defined('Redis::SCAN_PREFIX') && (bool) (\Redis::SCAN_PREFIX & $scan);

        return [$prefixesItself ? '' : $prefix, $prefix];
    }

    /**
     * Appends one rule to the stream: the tags it names, or a key prefix for
     * a clear. Then it makes sure no worker of this server serves what the
     * rule makes outdated. This worker's copy gets the rule when it can, the
     * local-invalidation note tells the other workers, and the reads this pool
     * remembers move on to the new rule.
     *
     * @param list<string> $tags
     */
    private function addRule(array $tags, ?string $prefix, bool $hard): bool
    {
        // the rule names tags ('t'), or a key prefix ('p') for a clear
        $rule = [];

        if ([] !== $tags) {
            $rule['t'] = $tags;
        }

        if (null !== $prefix) {
            $rule['p'] = $prefix;
        }

        // a prefix rule is always hard, so only a tag rule carries the flag
        if ($hard && [] !== $tags) {
            $rule[InvalidationRules::HARD] = 1;
        }

        // a clear of the whole pool makes every older rule pointless, so the
        // script may cut the stream back to it
        $subsumes = null !== $prefix && [] === $tags && $prefix === $this->poolNamespace;

        $arguments = [
            $this->rulesKey,
            json_encode($rule, \JSON_THROW_ON_ERROR),
            $this->rulesRetentionSeconds * 1000,
            $subsumes ? '1' : '0',
        ];

        // the time before the call: every rule appended before this moment
        // is at or before the rule the new one follows
        $beforeMs = microtime(true) * 1000;

        // if Redis fails, the invalidation failed: log it and say so
        try {
            $result = $this->rulesNode()->eval(self::SCRIPT_INVALIDATE, $arguments, 1);
        } catch (\Exception $e) {
            CacheItem::log($this->logger, 'Failed to append the invalidation rule: '.$e->getMessage(), ['exception' => $e, 'cache-adapter' => get_debug_type($this)]);

            return false;
        }

        if (!\is_array($result) || !\is_string($id = $result[0] ?? null)) {
            CacheItem::log($this->logger, 'Failed to append the invalidation rule.', ['cache-adapter' => get_debug_type($this)]);

            return false;
        }

        // the id of the rule the new one was appended after
        $previous = $result[1] ?? null;
        // the time after the reply: a fetch that begins later includes the rule
        $atMs = microtime(true) * 1000;

        // if this worker's copy ends exactly at the rule the new one was
        // appended after, nobody appended anything in between. The new rule is
        // then added straight from Redis's reply, and the copy holds the whole
        // stream up to and including it. (Reading the rule back could hit a
        // lagging replica that does not have it yet.) A copy that ends
        // anywhere else would skip what was appended in between, so it is
        // left as it is: the note below keeps every worker of this server from
        // using it until it is reloaded.
        if (null !== $this->rules && \is_string($previous) && $previous === $this->rules->last()) {
            $this->rules->append($id, $rule, '1' === ($result[2] ?? null));

            // the reply proves the copy held everything appended before the
            // call. So it counts as read just before the call (never earlier
            // than its read time already was), for this worker and for the
            // others, who would otherwise find it too old and reload
            $this->rulesReadAt = max($this->rulesReadAt ?? $beforeMs, $beforeMs);

            // the first invalidation of a request shares the copy now, before
            // the note is written, so a worker that sees the note finds a
            // shared copy that already holds the rule. Later ones in the same
            // request share once when the request ends, so a loop of
            // invalidations does not copy the whole set on every call
            if ($this->sharedInRequest) {
                $this->unshared = true;
            } else {
                $this->sharedInRequest = true;
                $this->shareHeldRules();
            }
        }

        // the local-invalidation note, here and for every worker of this
        // server: a copy read before now that lacks the rule is not used to
        // check items until it is reloaded (while Redis can be read and no
        // reload hangs, see rules())
        $this->writtenAtMs = $atMs;
        $this->writtenId = $id;
        $this->sharedRules?->written($atMs, $id);

        // reads this pool remembered that had seen the stream up to the rule
        // before get the new rule as their watermark. A value this pool
        // computes after its own invalidation is then not made outdated by
        // that same invalidation, as Symfony's tag semantics require. A read
        // with an older watermark did not see the rules in between, which may
        // come from elsewhere, so it keeps its watermark
        if (\is_string($previous)) {
            $this->records->restamp($previous, $id);
        }

        return true;
    }

    /**
     * The copy of the rules a read uses, and whether every item is a miss
     * computed without the stampede lock: [copy, unlocked].
     *
     * The copy is null when this worker holds none. With unlocked false, the
     * read checks its items against the copy. With unlocked true, the copy may
     * not be used, so every item is a miss. A value computed after such a miss
     * carries the copy's last rule id as its watermark, or, with no copy, is
     * not stored at all. A lock would then only make the workers computing it
     * queue one behind another.
     *
     * The copy is shared with the other workers of this server where a store
     * allows it (see SharedRules). The first of these situations that applies
     * decides:
     *
     * - This worker's copy, or a newer one another worker shared, was read
     *   less than $rulesCacheMs ago and is not missing an invalidation made on
     *   this server: it is used, with no call to Redis. Never with exact reads
     *   ($rulesCacheMs = 0).
     * - Redis is down (the last attempt to fetch the rules failed), or another
     *   worker's reload has run longer than SLOW_RELOAD_MS: the copy is used
     *   whatever its age, even one missing an invalidation made on this
     *   server. With no copy, every item is a miss computed without the lock.
     *   One worker tries Redis again once nobody holds the reload flag and
     *   RETRY_MS has passed since the last attempt ended.
     * - Nobody holds the reload flag: this worker takes it, reloads the copy
     *   (see reload()) and uses what it then holds. If that fetch fails, the
     *   server is in an outage, as in the case above.
     * - Another worker's reload has run for at most SLOW_RELOAD_MS, or it took
     *   the flag just before this worker could. Nobody waits for it: a copy
     *   read no more than $rulesCacheMs plus SLOW_RELOAD_MS ago, and not
     *   missing an invalidation made on this server, is used, with exact reads
     *   too. Otherwise every item is a miss computed without the lock, until
     *   the reload is done.
     *
     * Nobody fetches the rules but the worker holding the flag, or a worker
     * whose store refuses to record the flag (see reload()). This never
     * answers with an empty copy in place of the one held: with no rules, an
     * invalidated item would read as fresh, the one answer this must never
     * give.
     *
     * @return array{InvalidationRules|null, bool}
     */
    private function rules(): array
    {
        // a recent copy: this worker's own
        if (null !== $this->rules && $this->rulesAreFresh()) {
            return [$this->rules, false];
        }

        // or a newer one another worker shared, taken over here
        if (null !== $this->sharedRules && $this->adoptSharedRules($this->sharedRules) && $this->rulesAreFresh()) {
            return [$this->rules, false];
        }

        // did the last attempt to fetch the rules fail, and is another worker
        // reloading right now, since when?
        [$outage, $endedAtMs] = $this->lastAttempt();
        $startedAtMs = $this->sharedRules?->reloadStartedAt();
        $nowMs = microtime(true) * 1000;

        // an outage: Redis is down, or another worker's reload hangs
        if ($outage || (null !== $startedAtMs && $nowMs - $startedAtMs > self::SLOW_RELOAD_MS)) {
            // the one worker that tries Redis again
            if (null === $startedAtMs && $this->retryDue($endedAtMs)) {
                $this->reload();
            }

            return [$this->rules, null === $this->rules];
        }

        // nobody is reloading: this worker does
        if (null === $startedAtMs && $this->reload()) {
            return [$this->rules, null === $this->rules];
        }

        // another worker is reloading
        return [$this->rules, !$this->judgesWhileReloading()];
    }

    /**
     * Takes the reload flag and brings the copy up to date: XRANGE from the
     * copy's last id, a batch at a time, added to the copy, which then counts
     * as read at the moment the fetch began. The new copy is stored for the
     * other workers. When the stream was lost and started again, it replaces
     * theirs even though its ids look older.
     *
     * Returns false when another worker holds the flag. Returns true when
     * what this worker holds now is what the read should use: the reloaded
     * copy, a copy another worker stored meanwhile, or, after a failed
     * attempt, the copy held before.
     */
    private function reload(): bool
    {
        // only the worker that takes the flag reloads. Without a shared copy,
        // this worker is on its own and always may
        $shared = $this->sharedRules;
        $taken = null !== $shared ? $shared->lead() : true;

        if (false === $taken) {
            return false;
        }

        // the store could not record the flag (a full APCu, a full or
        // read-only disk). Left at that, nobody would ever reload, and every
        // read would be a miss for good. So this worker loads the rules on its
        // own (see $alone), and warns once per pool instance
        $this->alone = null === $taken;

        if ($this->alone) {
            $shared = null;
            $this->warnOnce('alone', 'The rule set store refused the reload flag: this worker loads the invalidation rules on its own and shares nothing until the store can be written.', ['cache-adapter' => get_debug_type($this)]);
        }

        try {
            // between this worker's look at the flag and taking it, another
            // worker may have finished a reload: its recent copy is taken
            // over, and its failed attempt is not repeated before RETRY_MS
            if (null !== $shared) {
                if ($this->adoptSharedRules($shared) && $this->rulesAreFresh()) {
                    return true;
                }

                [$outage, $endedAtMs] = $shared->lastAttempt();

                if ($outage && !$this->retryDue($endedAtMs)) {
                    return true;
                }
            }

            // start from this worker's copy, so only the rules added since are
            // fetched, or from an empty one, which loads the whole stream
            $rules = $this->rules ?? new InvalidationRules($this->poolNamespace, $this->rulesRetentionSeconds * 1000, $this->rulesMaxTags);
            // the time is taken before the fetch, and the copy's age counts
            // from it. A fetch that begins after the local-invalidation note's
            // time includes that note's rule (see behindLocalInvalidation())
            $startedAtMs = microtime(true) * 1000;
            $startedOver = false;
            $succeeded = $this->fetchRules($rules, $startedOver);

            // record how it went: a failure starts an outage, a success ends one
            $this->attemptEnded($startedAtMs, $succeeded);

            if ($succeeded) {
                // use the new copy, and share it with the other workers. A copy
                // rebuilt because the stream was lost replaces theirs, even
                // though its ids look older
                $this->rules = $rules;
                $this->rulesReadAt = $startedAtMs;

                if (null !== $shared) {
                    $this->stored($shared->store($rules, $startedAtMs, $startedOver));
                }

                // the fetch began after this process's own invalidations, so
                // the new copy holds their rules: nothing is left to share when
                // the request ends
                $this->unshared = false;
            }

            return true;
        } finally {
            // release the flag, whatever happened
            $shared?->release();
        }
    }

    /**
     * Brings a copy up to date with the stream: XRANGE from the copy's last
     * id, that id included, a batch at a time, until a batch comes back short.
     * $startedOver is set when the stream no longer held the copy's last id,
     * so the copy was rebuilt from what the stream holds now.
     *
     * On a connection that may read from a replica, some answers are asked
     * again of the primary first: one that would rebuild the copy (its last id
     * is not there, or nothing is), and one that ends before the newest rule
     * appended on this server. A replica that lags behind the copy's last id
     * answers exactly what a lost stream answers, and only the primary's
     * answer may drop what the copy holds. That costs one more round trip, in
     * a rare case.
     */
    private function fetchRules(InvalidationRules $rules, bool &$startedOver): bool
    {
        do {
            $from = $rules->last();
            $start = InvalidationRules::NONE === $from ? '-' : $from;

            try {
                $node = $this->rulesNode();
                $entries = $node->xRange($this->rulesKey, $start, '+', self::RULES_BATCH);

                // a replica's answer in doubt: ask the primary instead
                if (\is_array($entries) && $this->readsReplicas() && $this->replicaMayLag($rules, $entries)) {
                    $entries = self::entriesOf($node->eval(self::SCRIPT_RANGE, [$this->rulesKey, $start, (string) self::RULES_BATCH], 1));
                }
            } catch (\Exception $e) {
                CacheItem::log($this->logger, 'Failed to load the invalidation rules: '.$e->getMessage(), ['exception' => $e, 'cache-adapter' => get_debug_type($this)]);

                return false;
            }

            // an error reply (BUSY, LOADING) is a failed fetch, as a broken
            // connection is
            if (!\is_array($entries)) {
                CacheItem::log($this->logger, 'Failed to load the invalidation rules.', ['cache-adapter' => get_debug_type($this)]);

                return false;
            }

            // the stream no longer holds the copy's last id: absorb() rebuilds
            // the copy from this answer
            if (!$rules->continuedBy($entries)) {
                $startedOver = true;
            }

            $rules->absorb($entries);

            // a full batch may have more behind it; stop when a batch is short,
            // or when it did not move the copy forward
        } while (self::RULES_BATCH <= \count($entries) && $rules->last() !== $from);

        return true;
    }

    /**
     * Would this answer drop what the copy holds, or leave out a rule appended
     * on this server?
     *
     * @param array<array-key, mixed> $entries
     */
    private function replicaMayLag(InvalidationRules $rules, array $entries): bool
    {
        if (!$rules->continuedBy($entries)) {
            return true;
        }

        $last = array_key_last($entries);
        $last = null === $last ? $rules->last() : (string) $last;

        // a short batch is the end of the stream as this node knows it: it
        // must reach the newest rule appended on this server
        return \count($entries) < self::RULES_BATCH && InvalidationRules::isNewerId($this->newestWritten()[1], $last);
    }

    /**
     * An XRANGE answered through EVAL (a list of [id, [field, value, ...]]),
     * in the shape the client gives a plain XRANGE: id => [field => value].
     *
     * @return array<string, array<string, string>>|null
     */
    private static function entriesOf(mixed $reply): ?array
    {
        if (!\is_array($reply)) {
            return null;
        }

        $entries = [];

        foreach ($reply as $entry) {
            if (!\is_array($entry) || !\is_string($entry[0] ?? null) || !\is_array($entry[1] ?? null)) {
                return null;
            }

            $fields = [];
            $pairs = array_values($entry[1]);

            for ($i = 0; $i + 1 < \count($pairs); $i += 2) {
                if (\is_scalar($pairs[$i]) && \is_scalar($pairs[$i + 1])) {
                    $fields[(string) $pairs[$i]] = (string) $pairs[$i + 1];
                }
            }

            $entries[$entry[0]] = $fields;
        }

        return $entries;
    }

    /**
     * Whether a read may be answered by a replica: a cluster client told to
     * send reads to replicas, or to fall back to them, at all.
     */
    private function readsReplicas(): bool
    {
        if (null !== $this->readsReplicas) {
            return $this->readsReplicas;
        }

        try {
            return $this->readsReplicas = match (true) {
                $this->redis instanceof \RedisCluster => \RedisCluster::FAILOVER_NONE !== $this->redis->getOption(\RedisCluster::OPT_SLAVE_FAILOVER),
                $this->redis instanceof \Relay\Cluster => \Relay\Cluster::FAILOVER_NONE !== $this->redis->getOption(\Relay\Cluster::OPT_SLAVE_FAILOVER),
                default => false,
            };
        } catch (\Throwable) {
            // a connection that cannot be asked is asked again next time
            return false;
        }
    }

    /**
     * Takes over the shared copy if it holds a rule newer than this worker's
     * copy, or was read later. (A copy rebuilt after the stream was lost is
     * read later, though its ids are older.) True when a copy was taken over,
     * recent or not.
     */
    private function adoptSharedRules(SharedRules $shared): bool
    {
        if (null === $loaded = $shared->load($this->poolNamespace, $this->rulesRetentionSeconds * 1000, $this->rulesMaxTags)) {
            return false;
        }

        [$rules, $readAtMs] = $loaded;

        // the shared copy is no newer than this worker's: keep this one
        if (null !== $this->rules
            && !InvalidationRules::isNewerId($rules->last(), $this->rules->last())
            && $readAtMs <= ($this->rulesReadAt ?? -\INF)
        ) {
            return false;
        }

        // a shared copy that ends at or after this process's own rules holds
        // them: nothing is left to share
        $this->rules = $rules;
        $this->rulesReadAt = $readAtMs;
        $this->unshared = false;

        return true;
    }

    /**
     * Stores the copy this process added its own rule to, for the other
     * workers of this server to take over instead of reloading. It is stored
     * with this worker's read time: when its last fetch began or, if later,
     * the moment just before the invalidation call whose reply proved the
     * copy complete (see addRule()).
     */
    private function shareHeldRules(): void
    {
        // a worker that loads the rules on its own (the store refused the
        // flag) shares nothing
        if (null !== $this->rules && null !== $this->rulesReadAt && null !== $this->sharedRules && !$this->alone) {
            $this->stored($this->sharedRules->store($this->rules, $this->rulesReadAt));
        }

        $this->unshared = false;
    }

    /**
     * Shares the copy this request's later invalidations went into, if any. A
     * newer copy already there stays (see SharedRules::store()).
     */
    private function shareUnshared(): void
    {
        if (!$this->unshared) {
            return;
        }

        try {
            $this->shareHeldRules();
        } catch (\Exception $e) {
            CacheItem::log($this->logger, 'Failed to share the invalidation rules: '.$e->getMessage(), ['exception' => $e, 'cache-adapter' => get_debug_type($this)]);
        }
    }

    /**
     * Warns, once per pool instance, when the store refused the copy: the
     * other workers keep using the older copy there, and nothing else would
     * show it. A newer copy already there is not a refusal.
     */
    private function stored(?bool $stored): void
    {
        if (null === $stored) {
            $this->warnOnce('store', 'The rule set store refused the rule set: the other workers of this server keep judging by the older one until it can be written.', ['cache-adapter' => get_debug_type($this)]);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function warnOnce(string $name, string $message, array $context): void
    {
        if (!isset($this->warned[$name])) {
            $this->warned[$name] = true;
            $this->logger?->warning($message, $context);
        }
    }

    /**
     * Whether the last attempt to fetch the rules failed (an outage), and when
     * it ended. Where the copy is shared, that is the last attempt by any
     * worker of this server.
     *
     * @return array{bool, float|null}
     */
    private function lastAttempt(): array
    {
        if (null === $this->sharedRules || $this->alone) {
            return [$this->outage, $this->attemptEndedAtMs];
        }

        [$outage, $endedAtMs] = $this->sharedRules->lastAttempt();

        return [$outage, $endedAtMs];
    }

    /**
     * During an outage, may the stream be tried again: has RETRY_MS passed
     * since the last attempt ended?
     */
    private function retryDue(?float $endedAtMs): bool
    {
        return null === $endedAtMs || microtime(true) * 1000 - $endedAtMs >= self::RETRY_MS;
    }

    /**
     * Records the end of an attempt to fetch the rules: for the whole server
     * where the copy is shared, otherwise for this worker alone.
     */
    private function attemptEnded(float $startedAtMs, bool $succeeded): void
    {
        $endedAtMs = microtime(true) * 1000;

        if (null !== $this->sharedRules && !$this->alone) {
            $this->sharedRules->attemptEnded($startedAtMs, $succeeded, $endedAtMs);

            return;
        }

        $this->outage = !$succeeded;
        $this->attemptEndedAtMs = $endedAtMs;
    }

    /**
     * Is this worker's copy recent enough to use without asking anyone: read
     * less than $rulesCacheMs ago, and not missing an invalidation made on
     * this server? Never true with $rulesCacheMs = 0.
     */
    private function rulesAreFresh(): bool
    {
        return null !== $this->rulesReadAt
            && microtime(true) * 1000 - $this->rulesReadAt < $this->rulesCacheMs
            && !$this->behindLocalInvalidation();
    }

    /**
     * May this worker's copy be used while another worker reloads: read no
     * more than $rulesCacheMs plus SLOW_RELOAD_MS ago, and not missing an
     * invalidation made on this server?
     */
    private function judgesWhileReloading(): bool
    {
        return null !== $this->rulesReadAt
            && microtime(true) * 1000 - $this->rulesReadAt <= $this->rulesCacheMs + self::SLOW_RELOAD_MS
            && !$this->behindLocalInvalidation();
    }

    /**
     * Does this worker's copy lack the newest invalidation made on this
     * server? That is the case when the copy was read before that
     * invalidation and ends before its rule. Such a copy is not used to check
     * items until it is reloaded (except while Redis is down or a reload
     * hangs, see rules()). A copy read after the invalidation holds the rule,
     * because a copy's read time is taken before its fetch begins, and the
     * note's time after Redis replied. A copy that ends at or after the rule
     * holds it too, since the rule was added from Redis's reply. Holding no
     * copy does not count: there is nothing to use anyway. A copy is never
     * held without its read time.
     *
     * The local-invalidation note holds the largest time and the largest rule
     * id of the invalidations made here, which may come from two different
     * invalidations. That still finds every copy that lacks one of them. Such
     * a copy ends before that rule's id, so before the largest id. And its
     * fetch began before that rule's time, which was taken after Redis
     * replied, so before the largest time. It also flags no copy that lacks
     * nothing: a copy that ends before the largest id lacks that rule. A note
     * read while only one of its two values has been raised flags fewer
     * copies, as a note not yet written does.
     */
    private function behindLocalInvalidation(): bool
    {
        if (null === $this->rules) {
            return false;
        }

        [$atMs, $id] = $this->newestWritten();

        return null !== $atMs
            && $this->rulesReadAt <= $atMs
            && InvalidationRules::isNewerId($id, $this->rules->last());
    }

    /**
     * The newest invalidation made by this adapter or, through the
     * local-invalidation note, by any process of this server: its time and
     * its rule id.
     *
     * @return array{float|null, string}
     */
    private function newestWritten(): array
    {
        $atMs = $this->writtenAtMs;
        $id = $this->writtenId;

        if (null !== $mark = $this->sharedRules?->writtenMark()) {
            $atMs = max($atMs ?? $mark[0], $mark[0]);
            $id = InvalidationRules::isNewerId($mark[1], $id) ? $mark[1] : $id;
        }

        return [$atMs, $id];
    }

    /**
     * How long ago the soft rules that made an item outdated were added, when
     * that is within the grace. Null when it is not, when a hard rule (or a
     * clear, the floor or a lost stream) applies, or when the server's clock
     * cannot be read.
     *
     * @param list<string> $tags
     */
    private function softAge(InvalidationRules $rules, string $id, array $tags, string $mark): ?int
    {
        if (0 === $this->softGraceMs || null === $at = $rules->invalidatedAt($id, $tags, $mark)) {
            return null;
        }

        if (null === $now = $this->serverNowMs()) {
            return null;
        }

        $ageMs = max(0, $now - $at);

        return $ageMs < $this->softGraceMs ? $ageMs : null;
    }

    /**
     * @param list<string> $tags
     */
    private function keepAged(string $id, string $value, array $tags, int $ageMs): void
    {
        unset($this->aged[$id]);
        $this->aged[$id] = [$value, $tags, $ageMs];

        // limited like the remembered reads: the oldest goes first
        if (MissRecords::CAP < \count($this->aged)) {
            unset($this->aged[array_key_first($this->aged)]);
        }
    }

    /**
     * @param list<string> $ids
     */
    private function forgetAged(array $ids): void
    {
        if ([] === $this->aged) {
            return;
        }

        foreach ($ids as $id) {
            unset($this->aged[$id]);
        }
    }

    /**
     * The Redis server's clock in milliseconds. The offset from our clock is
     * read once per process and pool, and our clock carries it on from there.
     * Null when it cannot be read, so every invalidation counts as hard until
     * it can.
     */
    private function serverNowMs(): ?int
    {
        if (!isset(self::$clockOffsets[$this->rulesKey])) {
            $before = microtime(true) * 1000;

            try {
                $time = match (true) {
                    $this->redis instanceof \RedisCluster, $this->redis instanceof \Relay\Cluster => $this->redis->time($this->rulesKey),
                    $this->redis instanceof \RedisArray => $this->ownerOf($this->rulesKey)?->time(),
                    default => $this->redis->time(),
                };
            } catch (\Exception) {
                return null;
            }

            if (!\is_array($time) || !is_numeric($time[0] ?? null) || !is_numeric($time[1] ?? null)) {
                return null;
            }

            // the server answered somewhere between the two readings of ours
            $serverMs = (int) $time[0] * 1000 + intdiv((int) $time[1], 1000);
            self::$clockOffsets[$this->rulesKey] = $serverMs - ($before + microtime(true) * 1000) / 2;
        }

        return (int) (microtime(true) * 1000 + self::$clockOffsets[$this->rulesKey]);
    }

    /**
     * @return \Closure(string): string
     */
    private function bindIdOf(): \Closure
    {
        $idOf = \Closure::bind(fn (string $key): string => $this->getId($key), $this, AbstractTagAwareAdapter::class);
        \assert($idOf instanceof \Closure);

        return $idOf;
    }

    /**
     * Remembers a read a write may follow, where the guard is on.
     */
    private function remember(string $id, string $mark, string $epoch): void
    {
        if (0 < $this->guardWindowMs) {
            $this->records->take($id, $mark, $epoch);
        }
    }

    /**
     * The tags and the watermark a read found, or null when it found no hash
     * with tags: no item, a tombstone, or a key that is not an item.
     *
     * @return array{list<string>, string}|null
     */
    private static function tagsAndMark(mixed $row): ?array
    {
        if (!\is_array($row) || !\is_string($row[self::FIELD_TAGS] ?? null)) {
            return null;
        }

        $mark = $row[self::FIELD_MARK] ?? null;

        return [InvalidationRules::strings(json_decode($row[self::FIELD_TAGS], true)), \is_string($mark) ? $mark : InvalidationRules::NONE];
    }

    /**
     * The epoch a read found: the item's, empty for none, EPOCH_UNKNOWN where
     * the read of the item failed.
     */
    private static function epochOf(mixed $row): string
    {
        if (!\is_array($row)) {
            return self::EPOCH_UNKNOWN;
        }

        return \is_string($row[self::FIELD_EPOCH] ?? null) ? $row[self::FIELD_EPOCH] : '';
    }

    /**
     * The commands of a write that follows no read of this pool: the fields
     * and a new epoch. The epoch lives at least for the guard window, so a
     * write that follows an earlier miss is refused even after a short-lived
     * item has expired. The key's TTL is the longest of its fields' TTLs.
     *
     * @return list<array{0: string, ...}>
     */
    private function plainWrite(string $id, string $value, string $tags, string $mark, int $ttl): array
    {
        $fields = [self::FIELD_VALUE => $value, self::FIELD_TAGS => $tags, self::FIELD_MARK => $mark];

        // the guard is off: no epoch
        if (0 === $this->guardWindowMs) {
            return $this->fieldWrite($id, $fields, $ttl, $ttl);
        }

        // the item lives at least the guard window: its epoch lives as long
        if ($ttl >= $this->guardWindowMs) {
            return $this->fieldWrite($id, $fields + [self::FIELD_EPOCH => self::epoch()], $ttl, $ttl);
        }

        // a short-lived item: its data fields expire with its own TTL, while
        // the epoch and the key live for the guard window
        return [
            ...$this->fieldWrite($id, $fields, $ttl, null),
            ...$this->fieldWrite($id, [self::FIELD_EPOCH => self::epoch()], $this->guardWindowMs, $this->guardWindowMs),
        ];
    }

    /**
     * HSETEX of $fields with their TTL, and the key's TTL where $keyTtl says.
     * The first command answers 1 when every field was set.
     *
     * @param array<string, string> $fields
     *
     * @return list<array{0: string, ...}>
     */
    private function fieldWrite(string $id, array $fields, int $ttl, ?int $keyTtl): array
    {
        if ($this->writesTyped()) {
            // whole seconds, as every lifetime and the window are: a C long holds them
            $calls = [['hSetEx', $id, $fields, ['EX' => intdiv($ttl, 1000)]]];

            if (null !== $keyTtl) {
                $calls[] = ['expire', $id, intdiv($keyTtl, 1000)];
            }

            return $calls;
        }

        $arguments = [];

        foreach ($fields as $field => $value) {
            $arguments[] = $field;
            $arguments[] = $value;
        }

        // the fields expire on their own; the key's TTL is for the eviction policy
        $calls = [$this->command('HSETEX', $id, 'PX', (string) $ttl, 'FIELDS', (string) \count($fields), ...$arguments)];

        if (null !== $keyTtl) {
            $calls[] = $this->command('PEXPIRE', $id, (string) $keyTtl);
        }

        return $calls;
    }

    /**
     * Drops outdated items a read found: the data goes, and the epoch stays,
     * for no longer than the guard window (LT), so the write that recomputes
     * the item is checked like any other.
     *
     * @param list<string> $ids
     */
    private function dropStale(array $ids): void
    {
        if (0 === $this->guardWindowMs) {
            $this->unlink($ids);

            return;
        }

        $typed = $this->writesTyped();
        $calls = [];

        foreach ($ids as $id) {
            if ($typed) {
                $calls[] = ['hDel', $id, self::FIELD_VALUE, self::FIELD_TAGS, self::FIELD_MARK];
                $calls[] = ['hpexpire', $id, $this->guardWindowMs, [self::FIELD_EPOCH], 'LT'];

                continue;
            }

            $calls[] = $this->command('HDEL', $id, self::FIELD_VALUE, self::FIELD_TAGS, self::FIELD_MARK);
            $calls[] = $this->command('HPEXPIRE', $id, (string) $this->guardWindowMs, 'LT', 'FIELDS', '1', self::FIELD_EPOCH);
        }

        try {
            $this->pipeline($calls);
        } catch (\Exception $e) {
            CacheItem::log($this->logger, 'Failed to drop stale items: '.$e->getMessage(), ['exception' => $e, 'cache-adapter' => get_debug_type($this)]);
        }
    }

    /**
     * @param list<string> $ids
     */
    private function unlink(array $ids): bool
    {
        try {
            $this->redis->unlink($ids);
        } catch (\Exception $e) {
            CacheItem::log($this->logger, 'Failed to delete items: '.$e->getMessage(), ['exception' => $e, 'cache-adapter' => get_debug_type($this)]);

            return false;
        }

        return true;
    }

    /**
     * A fresh epoch: 16 hex characters, for a write or a delete to leave.
     */
    private static function epoch(): string
    {
        return bin2hex(random_bytes(8));
    }

    private static function guardedWriteSha(): string
    {
        return self::$guardedWriteSha ??= sha1(self::SCRIPT_GUARDED_WRITE);
    }

    /**
     * Whether a write goes out through the client's typed methods rather than
     * as a raw command: on a cluster that reads from replicas. There phpredis
     * sends a raw command to the node the last read of its slot went to, a
     * replica, which refuses a write. A typed write goes to the primary.
     * Relay's cluster client is given the same typed calls. Where the client
     * has no hsetex() (phpredis before 6.3), the raw command is all there is.
     */
    private function writesTyped(): bool
    {
        return ($this->redis instanceof \RedisCluster || $this->redis instanceof \Relay\Cluster)
            && $this->readsReplicas()
            && method_exists($this->redis, 'hsetex'); // @phpstan-ignore function.alreadyNarrowedType (the stubs describe phpredis 6.3; an older one has no hsetex())
    }

    /**
     * A write command by its wire name, addressed by key where a cluster or
     * an array routes by it.
     *
     * Everything travels as a string on purpose. phpredis marshals the
     * integers of its typed methods through a C long, which is 32 bits wide
     * on Windows, and a millisecond TTL a month long does not survive the
     * trip: HSETEX refuses the expiry outright and PEXPIRE, handed what is
     * now a negative number, deletes the key it was meant to keep. The
     * protocol is strings anyway.
     *
     * A raw command goes out exactly as written, while a typed call and a
     * script's KEYS get the client's key prefix. So the key a raw command
     * names is prefixed here. The address stays the bare key: phpredis's and
     * Relay's cluster clients both prefix it to find the slot (Relay's
     * rawCommand() leaves only the arguments unprefixed), and an array picks
     * the node of a typed call by it. A prefixed address would be prefixed
     * twice: phpredis would send the command to the wrong master and throw on
     * its MOVED, and Relay would follow the MOVED, a round trip late.
     *
     * @return array{0: string, ...}
     */
    private function command(string $command, string $key, string ...$arguments): array
    {
        $arguments = array_values($arguments);

        return $this->redis instanceof \Redis || $this->redis instanceof \Relay\Relay
            ? ['rawCommand', $command, $this->serverKey($key), ...$arguments]
            : ['rawCommand', $key, $command, $this->serverKey($key), ...$arguments];
    }

    /**
     * $key as the server stores it: with the client's prefix (OPT_PREFIX),
     * which a raw command does not add. The nodes of an array share the
     * option; the one that owns the key is asked.
     */
    private function serverKey(string $key): string
    {
        $client = $this->redis instanceof \RedisArray ? $this->ownerOf($key) : $this->redis;
        $prefixed = $client?->_prefix($key);

        return \is_string($prefixed) ? $prefixed : $key;
    }

    /**
     * The connection that answers for the rules stream: the client itself,
     * which routes by key, or the node of an array that owns the stream,
     * because an array sends a script to wherever its body hashes, not its
     * key.
     */
    private function rulesNode(): \Redis|\RedisCluster|\Relay\Relay|\Relay\Cluster
    {
        if (!$this->redis instanceof \RedisArray) {
            return $this->redis;
        }

        return $this->ownerOf($this->rulesKey) ?? throw new CacheException(\sprintf('No node of the array owns "%s".', $this->rulesKey));
    }

    /**
     * Runs [method, ...arguments] calls through one round trip where the
     * connection allows it, and one at a time where it does not. The answers
     * come back in the order of the calls.
     *
     * @param list<array{0: string, ...}> $calls
     *
     * @return list<mixed>
     */
    private function pipeline(array $calls): array
    {
        if ($this->redis instanceof \RedisArray) {
            // no raw command of its own, and nothing to pipeline across nodes:
            // every call goes to whichever node owns the key it names
            $results = [];

            foreach ($calls as $call) {
                $method = array_shift($call);

                // a script runs on the node that owns its key, the first argument
                if ('evalSha' === $method || 'eval' === $method) {
                    $results[] = $this->scriptOnOwningNode($method, $call);

                    continue;
                }

                if ('rawCommand' !== $method) {
                    $results[] = $this->redis->{$method}(...$call);

                    continue;
                }

                $arguments = [];

                foreach ($call as $argument) {
                    $arguments[] = \is_scalar($argument) ? (string) $argument : '';
                }

                // [key, command, ...]: the key is what says which node to ask
                $key = array_shift($arguments) ?? '';
                $results[] = $this->onOwningNode($key, ...$arguments);
            }

            return $results;
        }

        // phpredis pipelines across a cluster's slots where it has cluster
        // pipelines at all, which a release with pipeline() does
        if ($this->redis instanceof \RedisCluster && method_exists($this->redis, 'pipeline')) {
            return $this->clusterPipeline($this->redis, $calls);
        }

        // a single node speaks pipelining; a cluster, or a connection that
        // simply declines, gets one command at a time
        $pipe = null;

        if ($this->redis instanceof \Redis) {
            $pipe = $this->redis->multi(\Redis::PIPELINE);
        } elseif ($this->redis instanceof \Relay\Relay) {
            $pipe = $this->redis->multi(\Relay\Relay::PIPELINE);
        }

        if (!\is_object($pipe)) {
            return $this->oneAtATime($calls);
        }

        foreach ($calls as $call) {
            $method = array_shift($call);
            $pipe->{$method}(...$call);
        }

        try {
            $results = $pipe->exec();
        } catch (\Exception $e) {
            // a client may throw on a script the server does not hold, instead
            // of answering false in its slot. Then the batch is sent again one
            // call at a time, where such a refusal comes back as false and is
            // sent again with the script's full body. Every other call is a
            // read, or a write that can be repeated as it was. A guarded write
            // that was stored the first time is refused the second time,
            // because the item now holds its new epoch: it is reported as
            // failed though the value is stored, which is the safe direction
            if (!self::sendsAScript($calls)) {
                throw $e;
            }

            return $this->oneAtATime($calls);
        }

        if (!\is_array($results) || \count($results) !== \count($calls)) {
            // the connection went away under the batch: every call failed
            return array_fill(0, \count($calls), false);
        }

        return array_values($results);
    }

    /**
     * Runs [method, ...arguments] calls one round trip each, the answers in
     * the order of the calls.
     *
     * @param list<array{0: string, ...}> $calls
     *
     * @return list<mixed>
     */
    private function oneAtATime(array $calls): array
    {
        $results = [];

        foreach ($calls as $call) {
            $method = array_shift($call);

            try {
                $results[] = $this->redis->{$method}(...$call);
            } catch (\Exception $e) {
                // Relay throws where phpredis answers false: a script the
                // node does not hold is sent again with its full body
                if ('evalSha' !== $method) {
                    throw $e;
                }

                $results[] = false;
            }
        }

        return $results;
    }

    /**
     * @param list<array{0: string, ...}> $calls
     */
    private static function sendsAScript(array $calls): bool
    {
        foreach ($calls as $call) {
            if ('evalSha' === $call[0]) {
                return true;
            }
        }

        return false;
    }

    /**
     * One pipeline across the slots of a cluster, answered in the order of the
     * calls. An answer that is no list, or not one per call, means every call
     * failed, as on a single node.
     *
     * @param list<array{0: string, ...}> $calls
     *
     * @return list<mixed>
     */
    private function clusterPipeline(\RedisCluster $redis, array $calls): array
    {
        if (false === $redis->multi(\Redis::PIPELINE)) {
            return array_fill(0, \count($calls), false);
        }

        $sent = false;

        try {
            foreach ($calls as $call) {
                $method = array_shift($call);
                $redis->{$method}(...$call);
            }

            $sent = true;
            $results = $redis->exec();
        } finally {
            // a pipeline left open would take the next command into it
            if (!$sent) {
                try {
                    $redis->discard();
                } catch (\Throwable) {
                }
            }
        }

        if (!\is_array($results) || \count($results) !== \count($calls)) {
            return array_fill(0, \count($calls), false);
        }

        return array_values($results);
    }

    /**
     * Runs a script on the node of a RedisArray that owns its key: [script or
     * sha1, [key, ...arguments], 1].
     *
     * @param array<mixed> $call
     */
    private function scriptOnOwningNode(string $method, array $call): mixed
    {
        \assert($this->redis instanceof \RedisArray);

        $arguments = $call[1] ?? null;
        $key = \is_array($arguments) && \is_string($arguments[0] ?? null) ? $arguments[0] : '';
        $node = $this->ownerOf($key);

        return null !== $node ? $node->{$method}(...$call) : false;
    }

    /**
     * Sends one raw command to the node of a RedisArray that owns the key,
     * the array itself having no rawCommand() to route for us.
     */
    private function onOwningNode(string $key, string ...$arguments): mixed
    {
        $node = $this->ownerOf($key);

        return null !== $node ? $node->rawCommand(...$arguments) : false;
    }

    /**
     * The node of a RedisArray that owns $key, or null where the array names
     * no host for it.
     */
    private function ownerOf(string $key): ?\Redis
    {
        \assert($this->redis instanceof \RedisArray);

        $host = $this->redis->_target($key);
        $node = \is_string($host) ? $this->redis->_instance($host) : null;

        return $node instanceof \Redis ? $node : null;
    }

    /**
     * The adapter writes raw hash fields and reads them back with HMGET, so a
     * client that serializes or compresses values on its own would decode
     * what it never encoded. Symfony's marshallers are where that belongs. A
     * lazy proxy is left alone, as RedisTagAwareAdapter leaves it.
     */
    private static function rejectClientSideEncoding(\Redis|\RedisArray|\RedisCluster|\Relay\Relay|\Relay\Cluster $redis): void
    {
        $isRelay = $redis instanceof \Relay\Relay || $redis instanceof \Relay\Cluster;

        if (!$isRelay && !\in_array($redis::class, [\Redis::class, \RedisArray::class, \RedisCluster::class], true)) {
            return;
        }

        $checks = $isRelay
            ? [
                ['serializer', \Relay\Relay::OPT_SERIALIZER, \Relay\Relay::SERIALIZER_NONE],
                ['compression', \Relay\Relay::OPT_COMPRESSION, \Relay\Relay::COMPRESSION_NONE],
            ]
            : [['serializer', \Redis::OPT_SERIALIZER, \Redis::SERIALIZER_NONE]];

        if (!$isRelay && \defined('Redis::OPT_COMPRESSION')) {
            $checks[] = ['compression', \Redis::OPT_COMPRESSION, \Redis::COMPRESSION_NONE];
        }

        foreach ($checks as [$name, $option, $none]) {
            $value = $redis->getOption($option);

            // a RedisArray answers once per node
            foreach (\is_array($value) ? $value : [$value] as $v) {
                if ($none !== $v) {
                    throw new InvalidArgumentException(\sprintf('redis %s must be disabled when using "%s", use a marshaller instead.', $name, self::class));
                }
            }
        }
    }
}
