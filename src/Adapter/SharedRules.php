<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Adapter;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ApcuAdapter;

/**
 * The copy of the invalidation rules that the workers of one server share,
 * and the few things they must agree on while one of them reloads it.
 *
 * The idea in three sentences. Invalidating a tag deletes no item: it adds a
 * rule to a list in Redis, such as "items tagged product-42 written before
 * 12:00:00 are outdated". Every worker checks each item it reads against a
 * copy of that list kept in memory, without asking Redis. This class keeps
 * one copy where every worker of the server can reach it, so that one worker
 * reloads it from Redis about once a second (the adapter's $rulesCacheMs) and
 * the others take over its result.
 *
 * An example with two workers, A and B, on one server. The shared copy was
 * read from Redis at 11:59:59.000. At 12:00:00.000 worker A reads an item
 * and finds the copy a second old, so it takes the reload flag (lead()) and
 * asks Redis for the rules added since the copy's last one. A millisecond
 * later worker B reads an item. The flag is taken, the reload has run for
 * less than a second, and B's copy is less than two seconds old
 * ($rulesCacheMs plus a second), so B checks the item against its copy
 * without waiting. At 12:00:00.002 A's fetch returns. A stores the new copy
 * here (store()), records that the attempt succeeded (attemptEnded()) and
 * releases the flag (release()). B's next read takes over the new copy
 * (load()) and asks Redis for nothing.
 *
 * When something goes wrong:
 *
 * - If Redis does not answer, the reloader records an outage. Every worker
 *   keeps using the copy it has, however old, and one worker tries Redis
 *   again a second after the last attempt ended.
 * - If another worker is already reloading, nobody waits for it. A reload
 *   that runs longer than a second is treated as if Redis were down.
 * - If two reloads race, the slower one does not overwrite the newer copy
 *   that the faster one stored (see store()).
 * - If an invalidation is made on this server while the others hold an older
 *   copy, the local-invalidation note (below) keeps every worker from using a
 *   copy that lacks its rule until that copy is reloaded.
 * - If the store refuses to record the flag, the worker loads the rules on
 *   its own and shares nothing, so the server is never left without anyone
 *   to reload.
 *
 * Read more in the README, under "The shared rule set while it reloads".
 *
 * Why share at all. Under PHP-FPM every request is a fresh process with a
 * fresh adapter. An adapter with no copy would have to load the whole
 * stream, every invalidation of the retention window, before its first hit.
 * Kept where every worker of the server can reach it, the copy is loaded from
 * the start once per server. After that, the reloader only fetches the rules
 * added since the copy's last id, and stores the result for the others.
 *
 * Where it is kept. The store is APCu, called directly, unless the caller
 * hands in a PSR-6 pool. APCu is called directly because it keeps a PHP array
 * as it is and hands back a copy. A pool serialises the array on the way in
 * and parses it on the way out. A fresh worker takes over the copy on every
 * request, so that cost would be paid on every request, and grow with the
 * size of the copy. A pool is for servers without APCu, or for keeping the
 * copy where the application already keeps such things. In these comments a
 * "server" means the processes that share one store. The reload flag, the
 * record of the last attempt and the local-invalidation note are kept in that
 * store beside the copy, so they hold for all of those processes.
 *
 * The reload flag. Only the worker holding the flag reloads. The flag holds a
 * random owner token and the moment the reload began. One worker at a time
 * can take it, and only its owner releases it. It is there for a reloader
 * that dies, not for one that hangs, so it lasts longer than any reload: a
 * flag older than LEASE_MS counts as free again. Beside the flag lies the
 * record of the last attempt to fetch the rules: when it ended, whether it
 * failed (an outage), and when the last successful fetch began.
 *
 * The entry's key is made from the rules key and the connection's identity,
 * so two pools on one server with the same namespace but different Redis
 * servers never read each other's rules. How old a copy is comes from the
 * read time stored with it: when the fetch that read it began. The entry's
 * TTL only cleans up entries nobody reads any more.
 *
 * The local-invalidation note. Beside the copy lies a note of the newest
 * invalidation any process of this server made: when it was made, and the id
 * Redis gave its rule. A copy read before that time that holds no rule as new
 * as that id may lack the rule. So no worker here treats such a copy as
 * fresh, however recently it was read (see written()). The note only moves
 * forward.
 *
 * The entry records which format wrote it. In APCu, which only this package's
 * processes write, an entry of the current format is taken as it is, without
 * checking each rule, because that check costs more than the copy itself. An
 * entry of another format is ignored, as a missing one is. In a pool handed
 * in, others may write too, so every rule is checked.
 *
 * @internal
 */
final class SharedRules
{
    /**
     * How long an entry nobody touches stays in the store. How old the copy
     * is comes from the read time stored inside it, not from this. The flag
     * and the note in APCu have no expiry (see raise()).
     */
    public const TTL_SECONDS = 86_400;

    /**
     * In milliseconds: how long the reload flag stays taken before another
     * worker may take it over. That is longer than any reload whose read
     * timeout is kept short, and as long as PHP-FPM's default
     * max_execution_time.
     */
    public const LEASE_MS = 30_000;

    private const APCU_PREFIX = 'artigo.versioned.';

    /**
     * The format of the stored copy: one integer per rule (see
     * InvalidationRules).
     */
    private const FORMAT = 3;

    /**
     * The flag is one integer, so APCu can take and release it in one atomic
     * step: the millisecond the reload began, times this, plus a random number
     * below it that tells owners apart.
     */
    private const LEASE_NONCE = 1_000_000;

    private const KEY_FORMAT = 'format';
    private const KEY_RULES = 'rules';
    private const KEY_READ_AT_MS = 'read_at_ms';
    private const KEY_MARK_AT_US = 'at_us';
    private const KEY_MARK_ID = 'id';
    private const KEY_OUTAGE = 'outage';
    private const KEY_ENDED_AT_MS = 'ended_at_ms';
    private const KEY_SUCCESS_AT_MS = 'success_at_ms';
    private const SUFFIX_MARK = '_mark';
    private const SUFFIX_MARK_AT_US = '_mark_at_us';
    private const SUFFIX_MARK_ID = '_mark_id';

    /**
     * The local-invalidation note stores a rule id as one integer: the id's
     * milliseconds in the high bits, its sequence number in the lowest this
     * many bits (see packId()).
     */
    private const MARK_SEQUENCE_BITS = 20;
    private const MARK_SEQUENCE_MAX = (1 << self::MARK_SEQUENCE_BITS) - 1;
    private const SUFFIX_FLAG = '_flag';
    private const SUFFIX_ATTEMPT = '_attempt';

    /**
     * The flag value this instance holds, 0 when it holds none.
     */
    private int $lease = 0;

    /**
     * The instances of this process that hold the flag, so it can be released
     * when the process ends. A weak map, so it keeps no instance alive.
     *
     * @var \WeakMap<self, true>|null
     */
    private static ?\WeakMap $holders = null;

    /**
     * @param CacheItemPoolInterface|null $pool the store when it is not APCu
     */
    private function __construct(
        private readonly ?CacheItemPoolInterface $pool,
        private readonly string $key,
        private readonly string $mutexFile,
    ) {
    }

    /**
     * The shared copy for a pool, or null when there is to be none: the
     * caller passed false, or passed nothing and APCu is not available.
     */
    public static function of(CacheItemPoolInterface|false|null $pool, string $rulesKey, object $redis): ?self
    {
        if (false === $pool) {
            return null;
        }

        if (null === $pool && !self::apcuEnabled()) {
            return null;
        }

        $key = self::keyFor($rulesKey, self::identityOf($redis));

        return new self($pool, $key, sys_get_temp_dir().\DIRECTORY_SEPARATOR.'artigo-versioned-'.$key.'.lock');
    }

    /**
     * Whether this process can use APCu: the extension is loaded and enabled,
     * and under the CLI apc.enable_cli is on too (APCu is off for the CLI
     * otherwise). ApcuAdapter::isSupported() does not check that last part. A
     * store that refuses every write would leave the other processes without
     * the copy and the flag.
     */
    public static function apcuEnabled(): bool
    {
        if (!ApcuAdapter::isSupported()) {
            return false;
        }

        return !\in_array(\PHP_SAPI, ['cli', 'phpdbg'], true) || filter_var(\ini_get('apc.enable_cli'), \FILTER_VALIDATE_BOOL);
    }

    /**
     * A PSR-6 key (no reserved characters) that names the rules stream and
     * the Redis server it lives on.
     */
    public static function keyFor(string $rulesKey, string $identity): string
    {
        return 'rules_'.hash('xxh128', $rulesKey.'|'.$identity);
    }

    /**
     * What tells this connection's Redis apart from another one reached from
     * the same server. It is made of the host, port and database where the
     * client tells them (for an array, its hosts and each node's database),
     * the masters of a cluster, or only the client class when nothing else is
     * available. A client-side key prefix is added too, because under another
     * prefix the same namespace names a different stream.
     */
    public static function identityOf(object $redis): string
    {
        $parts = [$redis::class];

        foreach (['getHost', 'getPort', 'getDbNum', '_masters', '_hosts'] as $method) {
            if (!method_exists($redis, $method)) {
                continue;
            }

            try {
                $parts[] = $redis->{$method}();
            } catch (\Throwable) {
                // a client that cannot answer leaves the class to tell them apart
            }
        }

        // an array has no getDbNum() of its own: each node is asked which
        // database it was switched to
        if ($redis instanceof \RedisArray) {
            $databases = [];

            try {
                $hosts = $redis->_hosts();

                foreach (\is_array($hosts) ? $hosts : [] as $host) {
                    $node = \is_string($host) ? $redis->_instance($host) : null;

                    if ($node instanceof \Redis) {
                        $databases[$host] = $node->getDbNum();
                    }
                }
            } catch (\Throwable) {
                // a node that cannot answer leaves the hosts to tell them apart
            }

            $parts[] = ['databases' => $databases];
        }

        try {
            // an array has no _prefix() of its own; its nodes share the option
            $prefix = $redis instanceof \RedisArray ? $redis->getOption(\Redis::OPT_PREFIX) : (method_exists($redis, '_prefix') ? $redis->_prefix('') : null);
        } catch (\Throwable) {
            $prefix = null;
        }

        // an array answers once per node, all with the same prefix
        if (\is_array($prefix)) {
            $prefix = reset($prefix);
        }

        // no prefix adds nothing to the identity
        if (\is_string($prefix) && '' !== $prefix) {
            $parts[] = ['prefix' => $prefix];
        }

        return (string) json_encode($parts);
    }

    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * Is the copy kept in APCu directly (true), or in a pool handed in
     * (false)?
     */
    public function isApcu(): bool
    {
        return null === $this->pool;
    }

    /**
     * The stored copy and its read time (when the fetch that read it began),
     * or null when there is none or it cannot be read.
     *
     * @return array{InvalidationRules, float}|null
     */
    public function load(string $poolNamespace, int $retentionMs, int $maxTags = 0): ?array
    {
        $entry = $this->read($this->key);

        // an entry another version of this package wrote is ignored, as if
        // there were none
        if (!\is_array($entry) || self::FORMAT !== ($entry[self::KEY_FORMAT] ?? null)) {
            return null;
        }

        $readAtMs = $entry[self::KEY_READ_AT_MS] ?? null;
        $rules = null === $this->pool
            ? InvalidationRules::fromTrusted($entry[self::KEY_RULES] ?? null, $poolNamespace, $retentionMs, $maxTags)
            : InvalidationRules::fromArray($entry[self::KEY_RULES] ?? null, $poolNamespace, $retentionMs, $maxTags);

        if (null === $rules || (!\is_float($readAtMs) && !\is_int($readAtMs))) {
            return null;
        }

        return [$rules, (float) $readAtMs];
    }

    /**
     * Stores the copy for the other workers, unless another worker stored a
     * newer one meanwhile. Two reloads can race, and the slower one must not
     * replace the entry with a copy that lacks rules the faster one already
     * fetched. With $replace the copy replaces the stored one whatever its
     * ids: it was rebuilt from the start because the stream lost what the
     * stored copy holds, so its ids may look older. Returns true when stored,
     * false when a newer copy is already there, and null when the store
     * refused the write.
     */
    public function store(InvalidationRules $rules, float $readAtMs, bool $replace = false): ?bool
    {
        $held = $replace ? null : $this->read($this->key);

        // an entry of another format is overwritten, whatever it holds
        if (\is_array($held) && self::FORMAT === ($held[self::KEY_FORMAT] ?? null)) {
            $heldLast = \is_array($held[self::KEY_RULES] ?? null) && \is_string($held[self::KEY_RULES]['last'] ?? null)
                ? $held[self::KEY_RULES]['last']
                : InvalidationRules::NONE;

            if (InvalidationRules::isNewerId($heldLast, $rules->last())) {
                return false;
            }
        }

        $stored = $this->write($this->key, [
            self::KEY_FORMAT => self::FORMAT,
            self::KEY_RULES => $rules->toArray(),
            self::KEY_READ_AT_MS => $readAtMs,
        ]);

        return $stored ?: null;
    }

    /**
     * Writes the local-invalidation note: a process of this server appended
     * rule $id, and the append had returned by $atMs (this server's clock). A
     * copy read before then that holds no rule as new as $id may lack the
     * rule. The time and the id each only move forward, each in one step. So
     * when two invalidations race on one server, the later one's values stay;
     * a separate read and write could put the earlier one's back over them. In
     * APCu each value is an integer raised by compare-and-swap. In a pool, the
     * read and the write happen under this host's file lock.
     */
    public function written(float $atMs, string $id): void
    {
        // in microseconds, rounded up: never earlier than the moment the append
        // returned. A whole millisecond would count a copy whose fetch began
        // later in that millisecond as one read before it
        $at = (int) ceil($atMs * 1000);
        $packed = self::packId($id);

        if (null === $this->pool) {
            self::raise(self::APCU_PREFIX.$this->key.self::SUFFIX_MARK_ID, $packed);
            self::raise(self::APCU_PREFIX.$this->key.self::SUFFIX_MARK_AT_US, $at);

            return;
        }

        $this->underMutex(function () use ($at, $packed): void {
            [$heldAt, $heldId] = $this->poolMark() ?? [0, 0];

            $this->write($this->key.self::SUFFIX_MARK, [
                self::KEY_MARK_AT_US => max($heldAt, $at),
                self::KEY_MARK_ID => max($heldId, $packed),
            ]);
        });
    }

    /**
     * The local-invalidation note as written() left it: the time, in
     * milliseconds to the microsecond, and the rule id of the newest
     * invalidation a process of this server made. Null when there is none.
     *
     * @return array{float, string}|null
     */
    public function writtenMark(): ?array
    {
        if (null === $this->pool) {
            $at = $this->read($this->key.self::SUFFIX_MARK_AT_US);
            $packed = $this->read($this->key.self::SUFFIX_MARK_ID);
            $mark = \is_int($at) && \is_int($packed) ? [$at, $packed] : null;
        } else {
            $mark = $this->poolMark();
        }

        return null === $mark ? null : [(float) $mark[0] / 1000, self::unpackId($mark[1])];
    }

    /**
     * The note as a pool keeps it: its time, and its rule id packed into one
     * integer.
     *
     * @return array{int, int}|null
     */
    private function poolMark(): ?array
    {
        $entry = $this->read($this->key.self::SUFFIX_MARK);
        $at = \is_array($entry) ? $entry[self::KEY_MARK_AT_US] ?? null : null;
        $packed = \is_array($entry) ? $entry[self::KEY_MARK_ID] ?? null : null;

        return \is_int($at) && \is_int($packed) ? [$at, $packed] : null;
    }

    /**
     * Raises the integer APCu holds at $key to $value, unless it holds as
     * much already: of two workers raising it at once, the larger value stays.
     *
     * The key is written with no expiry. apcu_cas() keeps the expiry an entry
     * was created with, so with one the key would vanish a day after it was
     * first written, however often it changed. The flag is written the same
     * way, for the same reason (see leadInApcu()).
     */
    private static function raise(string $key, int $value): void
    {
        // a failed swap means another worker changed the value meanwhile, so
        // try again: a few rounds settle it
        for ($round = 0; $round < 64; ++$round) {
            $held = apcu_fetch($key, $found);

            if (true !== $found) {
                // nothing there yet: add it. Done when that worked, or when the
                // store refuses writes (the add failed and still nothing is there)
                if (apcu_add($key, $value) || !apcu_exists($key)) {
                    return;
                }

                continue;
            }

            // not a value this version wrote: remove it and start again
            if (!\is_int($held)) {
                apcu_delete($key);

                continue;
            }

            // done when it holds as much already, or the swap to ours worked
            if ($held >= $value || apcu_cas($key, $held, $value)) {
                return;
            }
        }
    }

    /**
     * A rule id as one integer that sorts the way the ids do: the milliseconds
     * in the high bits, the sequence number in the low MARK_SEQUENCE_BITS
     * bits. That lasts until the year 2248. A sequence number too large for its
     * bits is stored as the next millisecond's first id. That is later than
     * the rule, never earlier, so it can only make a copy look as if it lacked
     * the rule, never hide that it does.
     */
    private static function packId(string $id): int
    {
        [$ms, $sequence] = InvalidationRules::split($id);

        return $sequence > self::MARK_SEQUENCE_MAX ? ($ms + 1) << self::MARK_SEQUENCE_BITS : $ms << self::MARK_SEQUENCE_BITS | $sequence;
    }

    private static function unpackId(int $packed): string
    {
        return ($packed >> self::MARK_SEQUENCE_BITS).'-'.($packed & self::MARK_SEQUENCE_MAX);
    }

    /**
     * Takes the reload flag for this instance. Returns true when this instance
     * holds it now, false when another worker holds it, and null when the
     * store refused to record it (a full APCu, a full or read-only disk).
     *
     * @phpstan-impure any worker of the server may take the flag
     */
    public function lead(): ?bool
    {
        if (0 !== $this->lease) {
            return true;
        }

        $mine = (int) (microtime(true) * 1000) * self::LEASE_NONCE + random_int(1, self::LEASE_NONCE - 1);
        $taken = null === $this->pool ? $this->leadInApcu($mine) : $this->leadInPool($mine);

        if (true !== $taken) {
            return $taken;
        }

        $this->lease = $mine;

        // so that a reloader that dies of a fatal error still releases the
        // flag: one shutdown function per process releases it for every
        // instance that holds it
        if (null === self::$holders) {
            self::$holders = new \WeakMap();
            register_shutdown_function(static function (): void {
                $holders = [];

                foreach (self::$holders ?? [] as $holder => $held) {
                    $holders[] = $holder;
                }

                foreach ($holders as $holder) {
                    $holder->release();
                }
            });
        }

        self::$holders[$this] = true;

        return true;
    }

    /**
     * Releases the flag if this instance still holds it. If it was held too
     * long and another worker took it over, it stays with that worker.
     */
    public function release(): void
    {
        if (0 === $mine = $this->lease) {
            return;
        }

        $this->lease = 0;
        unset(self::$holders[$this]);

        if (null === $this->pool) {
            apcu_cas(self::APCU_PREFIX.$this->key.self::SUFFIX_FLAG, $mine, 0);

            return;
        }

        $this->underMutex(function () use ($mine): void {
            if ($mine === $this->read($this->key.self::SUFFIX_FLAG)) {
                $this->write($this->key.self::SUFFIX_FLAG, 0);
            }
        });
    }

    /**
     * When the reload in progress began, on this server's clock, or null when
     * nobody holds the flag.
     *
     * @phpstan-impure any worker of the server may take the flag
     */
    public function reloadStartedAt(): ?float
    {
        $held = $this->read($this->key.self::SUFFIX_FLAG);

        if (!\is_int($held) || 0 === $held || self::expired($held)) {
            return null;
        }

        return (float) intdiv($held, self::LEASE_NONCE);
    }

    /**
     * What the last attempt to fetch the rules from Redis left behind: whether
     * it failed (the server is in an outage), when it ended, and when the last
     * successful fetch began. A time is null when none is known.
     *
     * @return array{bool, float|null, float|null}
     *
     * @phpstan-impure any worker of the server records it
     */
    public function lastAttempt(): array
    {
        $entry = $this->read($this->key.self::SUFFIX_ATTEMPT);

        if (!\is_array($entry)) {
            return [false, null, null];
        }

        $endedAtMs = $entry[self::KEY_ENDED_AT_MS] ?? null;
        $successAtMs = $entry[self::KEY_SUCCESS_AT_MS] ?? null;

        return [
            true === ($entry[self::KEY_OUTAGE] ?? null),
            \is_float($endedAtMs) || \is_int($endedAtMs) ? (float) $endedAtMs : null,
            \is_float($successAtMs) || \is_int($successAtMs) ? (float) $successAtMs : null,
        ];
    }

    /**
     * Records an attempt to fetch the rules that ran from $startedAtMs to
     * $endedAtMs. A success ends an outage. A failure starts one, unless the
     * attempt began before the last success: that success read the stream
     * more recently, so the failure is out of date.
     */
    public function attemptEnded(float $startedAtMs, bool $succeeded, float $endedAtMs): void
    {
        [, , $successAtMs] = $this->lastAttempt();

        // a failure that began before the last success is ignored
        if (!$succeeded && null !== $successAtMs && $startedAtMs < $successAtMs) {
            return;
        }

        // the time of the last successful fetch only moves forward
        $this->write($this->key.self::SUFFIX_ATTEMPT, [
            self::KEY_OUTAGE => !$succeeded,
            self::KEY_ENDED_AT_MS => $endedAtMs,
            self::KEY_SUCCESS_AT_MS => $succeeded ? max($successAtMs ?? $startedAtMs, $startedAtMs) : $successAtMs,
        ]);
    }

    public function forget(): void
    {
        foreach ([$this->key, $this->key.self::SUFFIX_MARK, $this->key.self::SUFFIX_MARK_AT_US, $this->key.self::SUFFIX_MARK_ID, $this->key.self::SUFFIX_FLAG, $this->key.self::SUFFIX_ATTEMPT] as $key) {
            $this->delete($key);
        }
    }

    /**
     * Takes the flag in APCu: adds it where there is none, or swaps out a free
     * or expired one in one atomic step. An add that fails while nothing is
     * there means the store refuses writes (null).
     */
    private function leadInApcu(int $mine): ?bool
    {
        $key = self::APCU_PREFIX.$this->key.self::SUFFIX_FLAG;

        if (apcu_add($key, $mine)) {
            return true;
        }

        $held = apcu_fetch($key, $found);

        if (true !== $found || !\is_int($held)) {
            // nothing there, or a value this version did not write: remove it,
            // and race the other workers to add ours
            if (true === $found) {
                apcu_delete($key);
            }

            if (apcu_add($key, $mine)) {
                return true;
            }

            // another worker added its flag first, or the store refuses
            return apcu_exists($key) ? false : null;
        }

        // free (0) or expired: swap ours in, unless another worker changed it first
        return (0 === $held || self::expired($held)) && apcu_cas($key, $held, $mine);
    }

    /**
     * Takes the flag in a pool. A pool has no atomic compare-and-set, so the
     * read and the write happen under a short file lock shared by the
     * processes of this host. A write that fails means the store refuses
     * (null).
     */
    private function leadInPool(int $mine): ?bool
    {
        return $this->underMutex(function () use ($mine): ?bool {
            $held = $this->read($this->key.self::SUFFIX_FLAG);

            if (\is_int($held) && 0 !== $held && !self::expired($held)) {
                return false;
            }

            return $this->write($this->key.self::SUFFIX_FLAG, $mine) ? true : null;
        });
    }

    private static function expired(int $lease): bool
    {
        return microtime(true) * 1000 - intdiv($lease, self::LEASE_NONCE) >= self::LEASE_MS;
    }

    /**
     * Runs $critical while holding this host's file lock for the entry. Where
     * the lock file cannot be opened, it runs without the lock, and the last
     * write to the pool wins.
     *
     * @template T
     *
     * @param \Closure(): T $critical
     *
     * @return T
     */
    private function underMutex(\Closure $critical): mixed
    {
        set_error_handler(static fn (): bool => true);

        try {
            $handle = fopen($this->mutexFile, 'c');
        } finally {
            restore_error_handler();
        }

        if (!\is_resource($handle)) {
            return $critical();
        }

        try {
            $locked = flock($handle, \LOCK_EX);

            try {
                return $critical();
            } finally {
                if ($locked) {
                    flock($handle, \LOCK_UN);
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * An entry as stored, or null.
     */
    private function read(string $key): mixed
    {
        if (null === $this->pool) {
            $entry = apcu_fetch(self::APCU_PREFIX.$key, $success);

            return true === $success ? $entry : null;
        }

        try {
            $item = $this->pool->getItem($key);
        } catch (\Exception) {
            return null;
        }

        return $item->isHit() ? $item->get() : null;
    }

    private function delete(string $key): void
    {
        if (null === $this->pool) {
            apcu_delete(self::APCU_PREFIX.$key);

            return;
        }

        try {
            $this->pool->deleteItem($key);
        } catch (\Exception) {
            // nothing to delete, or the pool cannot be reached: nothing more to do
        }
    }

    /**
     * @param array<string, mixed>|int $entry
     */
    private function write(string $key, array|int $entry): bool
    {
        if (null === $this->pool) {
            return true === apcu_store(self::APCU_PREFIX.$key, $entry, self::TTL_SECONDS);
        }

        try {
            $item = $this->pool->getItem($key);
        } catch (\Exception) {
            return false;
        }

        $item->set($entry);
        $item->expiresAfter(self::TTL_SECONDS);

        return $this->pool->save($item);
    }
}
