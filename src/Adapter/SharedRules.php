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
 * The rule set, kept where it survives the request.
 *
 * Under PHP-FPM every request is a fresh process and a fresh adapter, and an
 * adapter that holds no rules has to load the whole stream - every
 * invalidation of the retention window - before its first hit. Kept where the
 * workers of a server can all reach it, the compacted set is loaded once per
 * server and then only followed: a worker whose window has passed fetches the
 * rules appended since the set's last id and stores the result for the next
 * one.
 *
 * The medium is APCu, spoken to directly, unless the caller hands in a PSR-6
 * pool of their own. Direct, because APCu keeps a PHP array as it is and
 * hands back a copy, where a pool serialises it on the way in and parses it on
 * the way out - and a fresh worker adopts the set on every request, so that
 * cost is paid per request, by the size of the set. A pool is for servers
 * without APCu, or for keeping the set where the application already keeps
 * such things.
 *
 * Where several workers cross the window together, one is elected to refresh
 * - a non-blocking flock() on a file under the temp dir, per host, released
 * by the OS if the leader dies, the primitive LockRegistry itself uses - and
 * the others keep the set they hold for that read.
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

    private const APCU_PREFIX = 'artigo.versioned.';

    /**
     * The shape of the stored set: one integer per rule (InvalidationRules).
     */
    private const FORMAT = 3;

    private const KEY_FORMAT = 'format';
    private const KEY_RULES = 'rules';
    private const KEY_READ_AT_MS = 'read_at_ms';
    private const KEY_WRITTEN_AT_MS = 'written_at_ms';
    private const KEY_WRITTEN_ID = 'written_id';
    private const SUFFIX_WRITTEN = '_written';

    /**
     * The election flag while this worker holds it.
     *
     * @var resource|null
     */
    private mixed $flag = null;

    /**
     * @param CacheItemPoolInterface|null $pool the medium when it is not APCu
     */
    private function __construct(
        private readonly ?CacheItemPoolInterface $pool,
        private readonly string $key,
        private readonly string $flagFile,
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
     * answers false to every write would leave the other processes waiting
     * for a set that never comes.
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
     * same server: host, port and database where the client can say, the
     * masters of a cluster, the class alone when nothing else is offered.
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
     * The stored set and the time it was read from the server, or null when
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
     */
    public function store(InvalidationRules $rules, float $readAtMs): bool
    {
        $held = $this->read($this->key);

        // an entry of another format is overwritten, whatever it holds
        if (\is_array($held) && self::FORMAT === ($held[self::KEY_FORMAT] ?? null)) {
            $heldLast = \is_array($held[self::KEY_RULES] ?? null) && \is_string($held[self::KEY_RULES]['last'] ?? null)
                ? $held[self::KEY_RULES]['last']
                : InvalidationRules::NONE;

            if (InvalidationRules::isNewerId($heldLast, $rules->last())) {
                return false;
            }
        }

        return $this->write($this->key, [
            self::KEY_FORMAT => self::FORMAT,
            self::KEY_RULES => $rules->toArray(),
            self::KEY_READ_AT_MS => $readAtMs,
        ]);
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

    public function forget(): void
    {
        foreach ([$this->key, $this->key.self::SUFFIX_WRITTEN] as $key) {
            if (null === $this->pool) {
                apcu_delete(self::APCU_PREFIX.$key);

                continue;
            }

            try {
                $this->pool->deleteItem($key);
            } catch (\Exception) {
                // nothing to forget, or nowhere to forget it
            }
        }
    }

    /**
     * Elects this worker to refresh the set. True when it should fetch,
     * false when another worker holds the flag and is fetching right now.
     *
     * A flag that cannot be had at all - no writable temp dir - elects
     * nobody, so the caller refreshes on its own rather than trust a set
     * nobody refreshes. Whoever was elected calls release() when the refresh
     * has landed, or failed; a leader that dies has the OS release for it.
     */
    public function lead(): bool
    {
        if (null !== $this->flag) {
            return true;
        }

        $handle = $this->openFlag();

        if (null === $handle) {
            return true;
        }

        if (flock($handle, \LOCK_EX | \LOCK_NB)) {
            $this->flag = $handle;

            return true;
        }

        fclose($handle);

        return false;
    }

    public function release(): void
    {
        if (null === $this->flag) {
            return;
        }

        flock($this->flag, \LOCK_UN);
        fclose($this->flag);
        $this->flag = null;
    }

    /**
     * Is a worker elected and refreshing right now?
     */
    public function isRefreshing(): bool
    {
        if (null !== $this->flag) {
            return true;
        }

        $handle = $this->openFlag();

        if (null === $handle) {
            return false;
        }

        $free = flock($handle, \LOCK_EX | \LOCK_NB);

        if ($free) {
            flock($handle, \LOCK_UN);
        }

        fclose($handle);

        return !$free;
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

    /**
     * @param array<string, mixed> $entry
     */
    private function write(string $key, array $entry): bool
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

    /**
     * @return resource|null
     */
    private function openFlag()
    {
        set_error_handler(static fn (): bool => true);

        try {
            $handle = fopen($this->flagFile, 'c');
        } finally {
            restore_error_handler();
        }

        return \is_resource($handle) ? $handle : null;
    }
}
