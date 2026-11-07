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
 * The rule set, kept where it survives the request, and everything the
 * workers sharing it need to agree on.
 *
 * Under PHP-FPM every request is a fresh process and a fresh adapter, and an
 * adapter that holds no rules has to load the whole stream - every
 * invalidation of the retention window - before its first hit. Kept where the
 * workers of a server can all reach it, the compacted set is loaded once per
 * server and then only followed: the worker that reloads it fetches the rules
 * appended since the set's last id and stores the result for the others.
 *
 * The medium is APCu, spoken to directly, unless the caller hands in a PSR-6
 * pool of their own. Direct, because APCu keeps a PHP array as it is and
 * hands back a copy, where a pool serialises it on the way in and parses it on
 * the way out - and a fresh worker adopts the set on every request, so that
 * cost is paid per request, by the size of the set. A pool is for servers
 * without APCu, or for keeping the set where the application already keeps
 * such things. The processes that share one medium are one server: the reload
 * flag, the outage and the mark of the newest invalidation live beside the
 * set and speak for all of them.
 *
 * The flag is a lease: an owner token and the moment the reload began, taken
 * by one worker at a time and released by its owner only. It outlasts any
 * reload, and exists for a reloader that dies, not for one that hangs: a
 * lease older than LEASE_MS is free again. Beside it lies the record of the
 * last attempt: when it ended, whether the stream could be read, and when the
 * last fetch that could read it began.
 *
 * The entry is keyed by the rules key and an identity of the connection, so
 * two pools that share one server and one namespace but talk to different
 * Redis servers never read each other's rules. Freshness is judged by the
 * read-at stamp stored with the set; the TTL only reclaims entries nobody
 * reads any more.
 *
 * Beside the set lies the mark of the newest invalidation a process of this
 * server made: when, and the rule id it got. A set read before that time and
 * holding no rule that new may lack it, so no worker here takes it for fresh,
 * whatever its stamp (see written()).
 *
 * The entry says which format wrote it. In APCu, which only this package's
 * processes write, an entry of the current format is taken as it is, without
 * checking each rule - that check costs more than the copy itself - and one of
 * another format is a miss, as an absent one is. In a pool handed in, others
 * may write too, and every rule is checked.
 *
 * @internal
 */
final class SharedRules
{
    /**
     * How long an untouched entry stays. Freshness is decided by the stamp
     * inside, not by this.
     */
    public const TTL_SECONDS = 86_400;

    /**
     * How long a lease holds the flag, in milliseconds: longer than any reload
     * whose read timeout is kept short, and as long as PHP-FPM's default
     * max_execution_time.
     */
    public const LEASE_MS = 30_000;

    private const APCU_PREFIX = 'artigo.versioned.';

    /**
     * The shape of the stored set: one integer per rule (InvalidationRules).
     */
    private const FORMAT = 3;

    /**
     * A lease is one integer, so APCu can take and release it atomically: the
     * millisecond it began times this, plus a nonce below it.
     */
    private const LEASE_NONCE = 1_000_000;

    private const KEY_FORMAT = 'format';
    private const KEY_RULES = 'rules';
    private const KEY_READ_AT_MS = 'read_at_ms';
    private const KEY_WRITTEN_AT_MS = 'written_at_ms';
    private const KEY_WRITTEN_ID = 'written_id';
    private const KEY_OUTAGE = 'outage';
    private const KEY_ENDED_AT_MS = 'ended_at_ms';
    private const KEY_SUCCESS_AT_MS = 'success_at_ms';
    private const SUFFIX_WRITTEN = '_written';
    private const SUFFIX_FLAG = '_flag';
    private const SUFFIX_ATTEMPT = '_attempt';

    /**
     * The lease this instance holds, 0 for none.
     */
    private int $lease = 0;

    /**
     * The instances of this process holding a lease, let go of when it ends;
     * weak, so that none is kept alive for it.
     *
     * @var \WeakMap<self, true>|null
     */
    private static ?\WeakMap $holders = null;

    /**
     * @param CacheItemPoolInterface|null $pool the medium when it is not APCu
     */
    private function __construct(
        private readonly ?CacheItemPoolInterface $pool,
        private readonly string $key,
        private readonly string $mutexFile,
    ) {
    }

    /**
     * The shared set for a pool, or null when there is not to be one: the
     * caller said so with false, or said nothing and APCu is not there.
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
     * Whether APCu can be spoken to from this process: the extension is
     * loaded and enabled, and - under the CLI, where it is off unless
     * apc.enable_cli says otherwise - enabled for the CLI too. That last
     * question ApcuAdapter::isSupported() does not ask, and a medium that
     * answers false to every write would leave the other processes without
     * the set and the flag.
     */
    public static function apcuEnabled(): bool
    {
        if (!ApcuAdapter::isSupported()) {
            return false;
        }

        return !\in_array(\PHP_SAPI, ['cli', 'phpdbg'], true) || filter_var(\ini_get('apc.enable_cli'), \FILTER_VALIDATE_BOOL);
    }

    /**
     * A PSR-6 key - no reserved characters - naming the rules stream and the
     * server it lives on.
     */
    public static function keyFor(string $rulesKey, string $identity): string
    {
        return 'rules_'.hash('xxh128', $rulesKey.'|'.$identity);
    }

    /**
     * What tells this connection's Redis from another one reached from the
     * same server: host, port and database where the client can say - an
     * array's hosts and the database of each node - the masters of a
     * cluster, the class alone when nothing else is offered -
     * and a client-side key prefix, under which the same namespace names
     * another stream.
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
                // a client that cannot say leaves the class to tell
            }
        }

        // an array has no getDbNum() of its own: each node says which
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
                // a node that cannot say leaves the hosts to tell
            }

            $parts[] = ['databases' => $databases];
        }

        try {
            // an array has no _prefix() of its own; its nodes share the option
            $prefix = $redis instanceof \RedisArray ? $redis->getOption(\Redis::OPT_PREFIX) : (method_exists($redis, '_prefix') ? $redis->_prefix('') : null);
        } catch (\Throwable) {
            $prefix = null;
        }

        // without one, the identity is what it always was
        if (\is_array($prefix)) {
            $prefix = reset($prefix);
        }

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
     * Is the set kept in APCu directly, or in a pool handed in?
     */
    public function isApcu(): bool
    {
        return null === $this->pool;
    }

    /**
     * The stored set and the time the fetch that read it began, or null when
     * there is none (or one we cannot read).
     *
     * @return array{InvalidationRules, float}|null
     */
    public function load(string $poolNamespace, int $retentionMs, int $maxTags = 0): ?array
    {
        $entry = $this->read($this->key);

        // an entry another version wrote is not ours to read: a miss
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
     * Stores the set for the next worker - unless another worker stored a
     * newer one meanwhile: two refreshes can race, and the slower one must
     * not roll the entry back behind the rules the faster one already fetched.
     * A set that started over, the stream having lost what the stored one
     * holds, replaces it whatever its ids ($replace). True when stored, false
     * when a newer set is there, null when the store refused to write it.
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
     * Records that a process of this server appended rule $id at $atMs (its
     * clock, after the append returned). A set read before then that holds
     * nothing as new as $id may lack the rule. Time and id each keep their
     * newest value: two invalidations racing on one server leave the mark of
     * the later one, or at worst of the earlier, whose window then bounds how
     * long the later rule can go unseen.
     */
    public function written(float $atMs, string $id): void
    {
        [$heldAtMs, $heldId] = $this->writtenMark() ?? [0.0, InvalidationRules::NONE];

        $this->write($this->key.self::SUFFIX_WRITTEN, [
            self::KEY_WRITTEN_AT_MS => max($heldAtMs, $atMs),
            self::KEY_WRITTEN_ID => InvalidationRules::isNewerId($heldId, $id) ? $heldId : $id,
        ]);
    }

    /**
     * The newest invalidation a process of this server made, as written()
     * recorded it, or null when none is.
     *
     * @return array{float, string}|null
     */
    public function writtenMark(): ?array
    {
        $entry = $this->read($this->key.self::SUFFIX_WRITTEN);

        if (!\is_array($entry)) {
            return null;
        }

        $atMs = $entry[self::KEY_WRITTEN_AT_MS] ?? null;
        $id = $entry[self::KEY_WRITTEN_ID] ?? null;

        if ((!\is_float($atMs) && !\is_int($atMs)) || !\is_string($id)) {
            return null;
        }

        return [(float) $atMs, $id];
    }

    /**
     * Takes the flag for this instance: true when it holds it now, false when
     * another worker's lease does, null when the store refused to record it -
     * a full APCu, a full or read-only disk.
     *
     * @phpstan-impure the flag is the server's
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

        // a reloader that dies of a fatal error lets go of it too: one
        // shutdown function per process, whatever the number of instances
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
     * Lets go of the flag, if this instance still holds it: a lease that ran
     * out and was taken by another worker stays theirs.
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
     * When the reload in flight began, on this server's clock, or null when
     * the flag is free.
     *
     * @phpstan-impure the flag is the server's
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
     * What the last attempt to fetch the stream left: whether the server is in
     * an outage, when that attempt ended, and when the last fetch that read
     * the stream began - each null where none is known.
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
     * Records an attempt to fetch the stream that ran from $startedAtMs to
     * $endedAtMs. A success ends the outage; a failure starts one, unless the
     * attempt began before the last success, which has read the stream since.
     */
    public function attemptEnded(float $startedAtMs, bool $succeeded, float $endedAtMs): void
    {
        [, , $successAtMs] = $this->lastAttempt();

        if (!$succeeded && null !== $successAtMs && $startedAtMs < $successAtMs) {
            return;
        }

        $this->write($this->key.self::SUFFIX_ATTEMPT, [
            self::KEY_OUTAGE => !$succeeded,
            self::KEY_ENDED_AT_MS => $endedAtMs,
            self::KEY_SUCCESS_AT_MS => $succeeded ? max($successAtMs ?? $startedAtMs, $startedAtMs) : $successAtMs,
        ]);
    }

    public function forget(): void
    {
        foreach ([$this->key, $this->key.self::SUFFIX_WRITTEN, $this->key.self::SUFFIX_FLAG, $this->key.self::SUFFIX_ATTEMPT] as $key) {
            $this->delete($key);
        }
    }

    /**
     * APCu adds the lease where there is none, and swaps it for a free or
     * lapsed one in one step. An add that fails with no key there is a refusal.
     */
    private function leadInApcu(int $mine): ?bool
    {
        $key = self::APCU_PREFIX.$this->key.self::SUFFIX_FLAG;

        if (apcu_add($key, $mine, self::TTL_SECONDS)) {
            return true;
        }

        $held = apcu_fetch($key, $found);

        if (true !== $found || !\is_int($held)) {
            // none there, or not a lease this version wrote: replaced, then
            // raced for
            if (true === $found) {
                apcu_delete($key);
            }

            if (apcu_add($key, $mine, self::TTL_SECONDS)) {
                return true;
            }

            return apcu_exists($key) ? false : null;
        }

        return (0 === $held || self::expired($held)) && apcu_cas($key, $held, $mine);
    }

    /**
     * A pool has no atomic write: the look and the write are serialised
     * between the processes of this host by a short lock on a file. A write
     * that fails is a refusal.
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
     * Runs $critical while holding the host's lock for this entry, or without
     * it where no file can be opened: the pool's own writes then decide.
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
            // nothing to forget, or nowhere to forget it
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
