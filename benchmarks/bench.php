<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * What does a tag cost when it falls - and what does a read cost after it?
 *
 * Runs this adapter, Symfony's RedisTagAwareAdapter and Symfony's generic
 * TagAwareAdapter (over a RedisAdapter) through the same scenarios - filling,
 * overwriting, reading, invalidating at three fan-out sizes, reading again,
 * giving the memory back, reading behind a long rule log, and reading from a
 * pool re-created every few reads, the way PHP-FPM does - and prints a
 * markdown table.
 *
 * Every row carries the command count and the number of socket reads Redis
 * did, because those do not move when the network does. The wall clock beside
 * them is worth exactly as much as the link it was measured over.
 *
 *   docker compose up -d
 *   php benchmarks/bench.php
 *   php benchmarks/bench.php items=50000 tags=20
 *   php benchmarks/bench.php stores=versioned
 *   php benchmarks/bench.php unique=1
 *   php benchmarks/bench.php unique=1 behind=100000
 *   php benchmarks/bench.php stores=versioned payload=model marshaller=deflate
 *   php benchmarks/bench.php stores=versioned payload=model marshaller=zstd
 *   php -d apc.enable_cli=1 -d apc.shm_size=256M benchmarks/bench.php adopt=15000,100000
 *   php -d apc.enable_cli=1 benchmarks/bench.php reads=100
 *   php benchmarks/bench.php guard=1
 *
 * Options: dsn=, items=, tags=, db=, stores=, client=relay, force=1,
 *          unique=1 (one tag of its own per item, the way an entity tag is),
 *          fresh=N (re-create the pool every N reads in the FPM rows; 10),
 *          behind=N (how many unrelated rules land behind the fill; 10000),
 *          payload=model (a ~2.5 KB entity instead of 256 repeated bytes),
 *          marshaller=deflate (Symfony's DeflateMarshaller around the default),
 *          marshaller=zstd (this package's ZstdMarshaller around the default)
 *
 * Three modes answer one question each and print their own table:
 *
 *   adopt=N,N... what a fresh pool pays to adopt a shared rule set of N
 *                tags, with the default bound and without one, and what the
 *                set takes in APCu (needs apc.enable_cli)
 *   reads=N      round trips per request reading N keys, warm and cold,
 *                through a get() loop and through getItems() and one batch
 *                write, for every store; a cluster DSN works here
 *   guard=1      what the invalidation guard costs this adapter: a fill
 *                through get() misses and through getItems() and batch
 *                writes, a read, and a delete with what it leaves behind for
 *                the window, the guard on and off
 *
 * Each store gets its own Redis database and must find it empty; pass force=1
 * to flush whatever is there instead. The counters this reads are server-wide,
 * so nothing else may be talking to that Redis while it runs.
 */

use Artigo\Cache\Adapter\SharedRules;
use Artigo\Cache\Adapter\VersionedRedisTagAwareAdapter;
use Artigo\Cache\Benchmarks\Meter;
use Artigo\Cache\Benchmarks\Result;
use Artigo\Cache\Marshaller\ZstdMarshaller;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Cache\Marshaller\DeflateMarshaller;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;
use Symfony\Component\Cache\PruneableInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/src/meter.php';

$options = [];

foreach (array_slice($argv, 1) as $argument) {
    if (str_contains($argument, '=')) {
        [$name, $value] = explode('=', $argument, 2);
        $options[$name] = $value;
    }
}

$dsn = (string) ($options['dsn'] ?? getenv('REDIS_DSN') ?: 'redis://127.0.0.1:6379');
$items = (int) ($options['items'] ?? 20000);
$tagCount = (int) ($options['tags'] ?? 18);
$firstDb = (int) ($options['db'] ?? 10);
$force = (bool) ($options['force'] ?? false);
$stores = explode(',', (string) ($options['stores'] ?? 'versioned,symfony,tagaware'));
// unique=1 gives every item one tag of its own on top, the way an entity tag does
$unique = (bool) ($options['unique'] ?? false);
// fresh=N re-creates the pool every N reads in the FPM rows: a request is a fresh process
$fresh = max(1, (int) ($options['fresh'] ?? 10));
// behind=N is how many unrelated rules land behind the fill in the long-log rows
$behind = max(1, (int) ($options['behind'] ?? 10_000));
$payload = 'model' === ($options['payload'] ?? '') ? 'model' : 'bytes';
$marshaller = match ($options['marshaller'] ?? '') {
    'deflate' => new DeflateMarshaller(new DefaultMarshaller()),
    'zstd' => new ZstdMarshaller(new DefaultMarshaller()),
    default => null,
};

// client=relay runs both adapters through Relay instead of ext-redis, which
// answers reads from a replica in this process rather than over the socket
$client = 'relay' === ($options['client'] ?? '') ? ['class' => Relay\Relay::class] : [];

// three fan-outs: a handful, a slice, and the whole set. The point of the
// exercise is what happens to the cost between them.
$fanouts = [
    'few' => 'bucket-0',
    'partial' => 'tenth',
    'all' => 'everything',
];

if (isset($options['adopt'])) {
    adopt($dsn, $client, array_map(intval(...), explode(',', (string) $options['adopt'])), $firstDb, $force);

    exit(0);
}

if (isset($options['guard'])) {
    guard($dsn, $client, $items, $tagCount, $firstDb, $force);

    exit(0);
}

if (isset($options['reads'])) {
    reads($dsn, $client, max(1, (int) $options['reads']), $stores, $marshaller);

    exit(0);
}

$probe = RedisAdapter::createConnection($dsn, $client);

if (!$probe instanceof Redis && !$probe instanceof Relay\Relay) {
    fwrite(\STDERR, 'This benchmark needs a plain ext-redis connection.'.\PHP_EOL);

    exit(1);
}

$server = $probe->info('server');
$memory = $probe->info('memory');
$clients = $probe->info('clients');
$eviction = is_array($memory) ? (string) ($memory['maxmemory_policy'] ?? '?') : '?';

echo sprintf('%s | %d items | %d tags each | Redis %s | maxmemory-policy %s%s',
    $dsn,
    $items,
    $tagCount,
    is_array($server) ? ($server['redis_version'] ?? '?') : '?',
    $eviction,
    \PHP_EOL,
);

if (is_array($clients) && (int) ($clients['connected_clients'] ?? 1) > 1) {
    fwrite(\STDERR, sprintf('! %d clients are connected. The command and read counters are server-wide,'.\PHP_EOL.'! so anything else talking to this Redis lands in these numbers.'.\PHP_EOL,
        (int) $clients['connected_clients']));
}

if (str_starts_with($eviction, 'allkeys') && in_array('symfony', array_map(trim(...), $stores), true)) {
    fwrite(\STDERR, '! RedisTagAwareAdapter refuses to write under an allkeys-* eviction policy,'.\PHP_EOL.'! so it cannot be measured here. This adapter is happy either way - which is'.\PHP_EOL.'! one of the differences, not a flaw in the setup.'.\PHP_EOL);
}

if (!SharedRules::apcuEnabled() && in_array('versioned', array_map(trim(...), $stores), true)) {
    fwrite(\STDERR, '! APCu is not enabled for this SAPI (apc.enable_cli), so the versioned pools share'.\PHP_EOL.'! no rule set here: a fresh pool loads the stream, and the two FPM rows will read alike.'.\PHP_EOL);
}

echo \PHP_EOL;

$meter = new Meter($probe);
$db = $firstDb;

foreach ($stores as $name) {
    $name = trim($name);

    if ('' === $name) {
        continue;
    }

    $connection = RedisAdapter::createConnection($dsn.'/'.$db, $client);

    if (!$connection instanceof Redis && !$connection instanceof Relay\Relay) {
        fwrite(\STDERR, 'This benchmark needs a plain ext-redis connection.'.\PHP_EOL);

        exit(1);
    }

    $size = $connection->dbSize();

    if ($size > 0 && !$force) {
        fwrite(\STDERR, sprintf('! database %d holds %d keys. Point db= somewhere empty, or pass force=1.'.\PHP_EOL, $db, $size));

        exit(1);
    }

    $connection->flushDb();

    // a pool as a request would build it; $shared = false keeps the versioned
    // pool's rule set to itself, for the row that shows what sharing saves
    $make = static fn (bool $shared = true): TagAwareAdapterInterface => match ($name) {
        'versioned' => new VersionedRedisTagAwareAdapter($connection, 'bench', marshaller: $marshaller, rulesCache: $shared ? null : false),
        'symfony' => new RedisTagAwareAdapter($connection, 'bench', 0, $marshaller),
        'tagaware' => new TagAwareAdapter(new RedisAdapter($connection, 'bench', 0, $marshaller)),
        default => throw new RuntimeException(sprintf('Unknown store "%s"; pick from versioned, symfony, tagaware.', $name)),
    };

    echo sprintf('%s%s', $name, \PHP_EOL);

    try {
        run($meter, $make, $name, $items, $tagCount, $fanouts, $unique, $fresh, $connection, $behind, $payload);
    } catch (Throwable $e) {
        fwrite(\STDERR, sprintf('  ! %s: %s'.\PHP_EOL, $name, $e->getMessage()));
        $meter->note($name, 'failed', $e->getMessage());
    }

    $connection->flushDb();
    ++$db;
    echo \PHP_EOL;
}

table($meter->results());

/**
 * @param array<string, string> $fanouts
 */
function run(
    Meter $meter,
    Closure $make,
    string $name,
    int $items,
    int $tagCount,
    array $fanouts,
    bool $unique,
    int $fresh,
    Redis|Relay\Relay $connection,
    int $behind = 10_000,
    string $payload = 'bytes',
): void {
    /** @var TagAwareAdapterInterface $pool */
    $pool = $make();
    $value = 'model' === $payload ? model(1) : str_repeat('x', 256);

    // an untimed pass first, so class loading and script caching do not land
    // on whichever scenario happens to run first
    fill($pool, 50, $tagCount, $unique, value: $value);
    $pool->clear();

    $report = static function (Result $result): void {
        echo sprintf('  %-24s %8.3f s  %9s cmds  %9s reads%s',
            $result->scenario,
            $result->seconds,
            number_format($result->commands),
            number_format($result->reads),
            \PHP_EOL,
        );
    };

    $received = $meter->bytesReceived();
    $report($meter->measure($name, 'fill', static fn () => fill($pool, $items, $tagCount, $unique, value: $value)));
    // what the fill put on the wire, per item: what a marshaller saves
    $perItem = ($meter->bytesReceived() - $received) / $items;
    $meter->note($name, 'fill: bytes received per item', sprintf('%s B', number_format($perItem)));
    echo sprintf('  %-24s %s B%s', 'bytes per item', number_format($perItem), \PHP_EOL);
    $report($meter->measure($name, 'overwrite', static fn () => fill($pool, $items, $tagCount, $unique, value: $value)));
    $report($meter->measure($name, 'read hit', static fn () => read($pool, $items)));

    $filled = $meter->usedMemory();

    foreach ($fanouts as $label => $tag) {
        $matched = match ($label) {
            'few' => (int) ceil($items / 20),
            'partial' => (int) ceil($items / 10),
            default => $items,
        };

        $report($meter->measure($name, sprintf('invalidate %s (%s)', $label, number_format($matched)), static function () use ($pool, $tag): void {
            $pool->invalidateTags([$tag]);
        }));
    }

    $report($meter->measure($name, 'read after invalidation', static fn () => read($pool, $items)));

    // what the invalidation actually gave back, once the readers have been
    // through: eagerly for Symfony, lazily here
    $reclaimed = $filled - $meter->usedMemory();
    $meter->note($name, 'memory reclaimed', sprintf('%.1f MB', $reclaimed / 1048576));
    echo sprintf('  %-24s %s%s', 'memory reclaimed', sprintf('%.1f MB', $reclaimed / 1048576), \PHP_EOL);

    // what a long rule log costs the readers. Ten thousand invalidations of
    // tags nothing carries land behind a fresh fill, so every item is older
    // than every rule - the worst case for a read that has to judge its item
    // against the rules it has not seen. The eager adapter has no log and
    // pays at invalidation time instead.
    $pool->clear();
    fill($pool, $items, $tagCount, $unique, value: $value);
    $rules = number_format($behind, 0, '.', ' ');

    $report($meter->measure($name, sprintf('invalidate %s unrelated tags', $rules), static function () use ($pool, $behind): void {
        for ($i = 0; $i < $behind; ++$i) {
            $pool->invalidateTags(['nobody-'.$i]);
        }
    }));
    $report($meter->measure($name, sprintf('read hit, %s rules behind', $rules), static fn () => read($pool, $items)));

    // the FPM model: a request is a fresh process, and a fresh pool holds no
    // rules. Behind the same 10 000 rules the pool is re-created every $fresh
    // reads, so each "request" pays whatever a cold pool pays before its first
    // hit. The versioned pool shares its rule set through APCu where it can;
    // its second row keeps the set to itself, and shows what that costs
    $report($meter->measure($name, sprintf('read hit, fresh pool every %d reads', $fresh), static fn () => readFresh($make, $items, $fresh)));

    if ('versioned' === $name) {
        $report($meter->measure($name, sprintf('read hit, fresh pool every %d reads, no shared rules', $fresh), static fn () => readFresh(static fn () => $make(false), $items, $fresh)));
    }

    // how many of those reads were still hits: past the bound a floor takes
    // the place of the oldest rules, and an item older than it is a miss
    $hits = 0;

    for ($i = 0; $i < $items; ++$i) {
        $hits += $make()->getItem('item-'.$i)->isHit() ? 1 : 0;
    }

    $meter->note($name, sprintf('hits after %s rules', $rules), sprintf('%s of %s', number_format($hits), number_format($items)));
    echo sprintf('  %-24s %s of %s%s', 'hits after the rules', number_format($hits), number_format($items), \PHP_EOL);

    // What the server is left holding when nobody reads these items again -
    // the case an operator lives with, since a cache is mostly written to and
    // only sometimes read back. Each round starts from an empty database, so
    // what is counted afterwards was put there by the adapter and by nothing
    // else.

    $connection->flushDb();
    $pool = $make();
    // a short life, then a full pass so the server drops what has expired
    fill($pool, $items, $tagCount, $unique, 2);
    sleep(3);
    expireNow($connection);
    $meter->note($name, 'left after the items expired', leftovers($connection));
    echo sprintf('  %-24s %s%s', 'left after expiry', leftovers($connection), \PHP_EOL);

    $connection->flushDb();
    $pool = $make();
    fill($pool, $items, $tagCount, $unique);
    $pool->invalidateTags(['everything']);
    $meter->note($name, 'left after invalidating everything', leftovers($connection));
    echo sprintf('  %-24s %s%s', 'left after invalidation', leftovers($connection), \PHP_EOL);

    // and what an operator can get back on purpose. Symfony's generic adapter
    // is Pruneable and forwards to its pool, which a Redis pool is not, so it
    // prunes nothing; RedisTagAwareAdapter gains a prune in 8.2 that collects
    // the tag Sets' dangling members rather than items, which it deleted when
    // the tag fell.
    if ($pool instanceof PruneableInterface) {
        $report($meter->measure($name, 'prune', static fn () => $pool->prune()));
        $meter->note($name, 'left after prune', leftovers($connection));
        echo sprintf('  %-24s %s%s', 'left after prune', leftovers($connection), \PHP_EOL);
    } else {
        $meter->note($name, 'left after prune', 'not pruneable');
    }
}

/**
 * The same work through the versioned pool with the guard on and off: what
 * the write that follows a miss costs as a script, and what a delete leaves
 * behind for the window.
 *
 * @param array<string, string> $client
 */
function guard(string $dsn, array $client, int $items, int $tagCount, int $db, bool $force): void
{
    $connection = RedisAdapter::createConnection($dsn.'/'.$db, $client);

    if (!$connection instanceof Redis && !$connection instanceof Relay\Relay) {
        fwrite(\STDERR, 'This benchmark needs a plain ext-redis connection.'.\PHP_EOL);

        exit(1);
    }

    if ($connection->dbSize() > 0 && !$force) {
        fwrite(\STDERR, sprintf('! database %d is not empty. Point db= somewhere empty, or pass force=1.'.\PHP_EOL, $db));

        exit(1);
    }

    $meter = new Meter($connection);
    $value = str_repeat('x', 256);
    $tags = static fn (int $index): array => tagsFor($index, $tagCount, $items);

    foreach (['the guard on' => 60, 'guardWindowSeconds: 0' => 0] as $name => $window) {
        $make = static fn (): VersionedRedisTagAwareAdapter => new VersionedRedisTagAwareAdapter($connection, 'bench', guardWindowSeconds: $window);

        echo $name.\PHP_EOL;
        $connection->flushDb();
        $empty = $meter->usedMemory();
        $pool = $make();

        $result = $meter->measure($name, 'fill through get() misses', static function () use ($pool, $items, $tags, $value): void {
            for ($i = 0; $i < $items; ++$i) {
                $pool->get('item-'.$i, static function (ItemInterface $item) use ($i, $tags, $value): string {
                    $item->tag($tags($i));
                    $item->expiresAfter(3600);

                    return $value;
                });
            }
        });
        echo sprintf('  %-48s %8.3f s  %9s cmds  %9s reads%s', $result->scenario, $result->seconds, number_format($result->commands), number_format($result->reads), \PHP_EOL);

        $connection->flushDb();
        $pool = $make();

        $result = $meter->measure($name, 'fill through getItems() + commit, 100 a batch', static function () use ($pool, $items, $tags, $value): void {
            for ($first = 0; $first < $items; $first += 100) {
                $keys = array_map(static fn (int $i): string => 'item-'.$i, range($first, min($items, $first + 100) - 1));

                foreach ($pool->getItems($keys) as $key => $item) {
                    if (!$item->isHit()) {
                        $pool->saveDeferred($item->set($value)->tag($tags((int) substr((string) $key, 5)))->expiresAfter(3600));
                    }
                }

                $pool->commit();
            }
        });
        echo sprintf('  %-48s %8.3f s  %9s cmds  %9s reads%s', $result->scenario, $result->seconds, number_format($result->commands), number_format($result->reads), \PHP_EOL);

        $result = $meter->measure($name, 'read hit', static fn () => read($pool, $items));
        echo sprintf('  %-48s %8.3f s  %9s cmds  %9s reads%s', $result->scenario, $result->seconds, number_format($result->commands), number_format($result->reads), \PHP_EOL);

        $result = $meter->measure($name, sprintf('delete every item, %s a call', number_format(1000)), static function () use ($pool, $items): void {
            for ($first = 0; $first < $items; $first += 1000) {
                $pool->deleteItems(array_map(static fn (int $i): string => 'item-'.$i, range($first, min($items, $first + 1000) - 1)));
            }
        });
        echo sprintf('  %-48s %8.3f s  %9s cmds  %9s reads%s', $result->scenario, $result->seconds, number_format($result->commands), number_format($result->reads), \PHP_EOL);

        // the rules stream stays either way; the tombstones are what the window holds
        $held = max(0, $meter->usedMemory() - $empty);
        $meter->note($name, 'left after the delete', sprintf('%s, %.1f MB', leftovers($connection), $held / 1048576));
        echo sprintf('  %-48s %s, %.1f MB%s', 'left after the delete', leftovers($connection), $held / 1048576, \PHP_EOL);
    }

    $connection->flushDb();
    echo \PHP_EOL;
    table($meter->results());
}

/**
 * A full pass over the keyspace, which also makes the server drop every
 * expired key it meets: what has expired is gone logically at once, but is
 * still held until something looks at it or the expiry cycle gets there.
 */
function expireNow(Redis|Relay\Relay $connection): void
{
    foreach ([1, 2] as $ignored) {
        $cursor = null;

        do {
            $connection->scan($cursor, null, 5_000);
        } while ($cursor);
    }
}

/**
 * What the server still holds, by kind. The shape of what an adapter leaves
 * behind says as much as its size: an item is one thing, a tag Set that
 * outlives every item it named is another, and a tag version key that nothing
 * will ever delete is a third.
 */
function leftovers(Redis|Relay\Relay $connection): string
{
    $parts = [];

    foreach (['string', 'hash', 'set', 'stream'] as $type) {
        $cursor = '0';
        $keys = 0;
        $members = 0;
        $versions = 0;

        do {
            $answer = $connection->rawCommand('SCAN', $cursor, 'COUNT', '5000', 'TYPE', $type);

            if (!is_array($answer) || !isset($answer[0], $answer[1]) || !is_array($answer[1])) {
                break;
            }

            $cursor = (string) $answer[0];
            $batch = $answer[1];
            $keys += count($batch);

            foreach ($batch as $key) {
                if ('set' === $type) {
                    $members += (int) $connection->sCard((string) $key);
                } elseif ('string' === $type && str_contains((string) $key, "\1tags\1")) {
                    // TagAwareAdapter's per-tag version, written without a TTL
                    ++$versions;
                }
            }
        } while ('0' !== $cursor);

        if (0 === $keys) {
            continue;
        }

        $many = 1 === $keys ? '' : 's';

        $parts[] = match (true) {
            'set' === $type => sprintf('%s set%s holding %s member%s', number_format($keys), $many, number_format($members), 1 === $members ? '' : 's'),
            'string' === $type && $versions > 0 => sprintf('%s string%s (%s of them tag versions)', number_format($keys), $many, number_format($versions)),
            'hash' === $type => sprintf('%s hash%s', number_format($keys), 1 === $keys ? '' : 'es'),
            default => sprintf('%s %s%s', number_format($keys), $type, $many),
        };
    }

    return [] === $parts ? 'nothing' : implode(', ', $parts);
}

/**
 * Reads every item once, from a pool re-created every $every reads - a
 * request being a fresh process, and a fresh pool holding no rules.
 */
function readFresh(Closure $make, int $items, int $every): void
{
    $pool = null;

    for ($i = 0; $i < $items; ++$i) {
        if (null === $pool || 0 === $i % $every) {
            /** @var TagAwareAdapterInterface $pool */
            $pool = $make();
        }

        $pool->getItem('item-'.$i)->get();
    }
}

function fill(TagAwareAdapterInterface $pool, int $items, int $tagCount, bool $unique = false, int $lifetime = 3600, mixed $value = null): void
{
    $payload = $value ?? str_repeat('x', 256);

    for ($i = 0; $i < $items; ++$i) {
        $item = $pool->getItem('item-'.$i);
        $item->set($payload);
        $item->expiresAfter($lifetime);
        $item->tag(tagsFor($i, $tagCount, $items, $unique));
        $pool->saveDeferred($item);

        if (0 === $i % 1000) {
            $pool->commit();
        }
    }

    $pool->commit();
}

function read(TagAwareAdapterInterface $pool, int $items): void
{
    for ($i = 0; $i < $items; ++$i) {
        $pool->getItem('item-'.$i)->get();
    }
}

/**
 * Every item carries "everything"; a twentieth carries "bucket-0" and a tenth
 * carries "tenth", which is what makes the three fan-outs. With $unique, one
 * of the tags is the item's own - the entity tag applications really use,
 * which no other item shares and no tag-version cache can have seen.
 *
 * @return list<string>
 */
function tagsFor(int $index, int $tagCount, int $items, bool $unique = false): array
{
    $tags = ['everything', 'bucket-'.($index % 20)];

    if ($unique) {
        $tags[] = 'entity-'.$index;
    }

    if (0 === $index % 10) {
        $tags[] = 'tenth';
    }

    for ($t = count($tags); $t < $tagCount; ++$t) {
        $tags[] = sprintf('filler-%d-%d', $t, $index % 97);
    }

    return $tags;
}

/**
 * An entity the way an application caches one: nested arrays of short
 * strings and numbers, about 2.5 KB serialized. Text is drawn from a small
 * vocabulary, as product copy is, so it compresses the way real copy does
 * rather than the way repeated bytes do.
 *
 * @return array<string, mixed>
 */
function model(int $seed): array
{
    mt_srand($seed);
    $words = ['steel', 'frame', 'light', 'outdoor', 'water', 'resistant', 'black', 'compact', 'battery', 'model', 'cable', 'set', 'pack', 'premium', 'grip', 'season', 'large', 'small', 'mount', 'travel', 'kit', 'door', 'glass', 'wood', 'oak', 'linen', 'cotton', 'blue', 'green', 'warranty'];
    $text = static function (int $count) use ($words): string {
        $out = [];

        for ($i = 0; $i < $count; ++$i) {
            $out[] = $words[mt_rand(0, count($words) - 1)];
        }

        return ucfirst(implode(' ', $out)).'.';
    };

    $attributes = [];

    for ($i = 0; $i < 12; ++$i) {
        $attributes[] = ['code' => 'attr_'.mt_rand(100, 999), 'label' => $text(3), 'value' => $text(4)];
    }

    return [
        'id' => mt_rand(1, 9_999_999),
        'ean' => (string) mt_rand(1_000_000_000_000, 9_999_999_999_999),
        'name' => $text(6),
        'description' => $text(90),
        'price' => ['amount' => mt_rand(100, 99_999), 'currency' => 'EUR', 'vat' => 20],
        'stock' => ['available' => mt_rand(0, 500), 'warehouse' => 'W'.mt_rand(1, 9)],
        'attributes' => $attributes,
        'categories' => [mt_rand(1, 500), mt_rand(1, 500), mt_rand(1, 500)],
    ];
}

/**
 * What a fresh pool pays to adopt a shared rule set of each size, with the
 * default bound and without one: the ms per fresh pool and the bytes the set
 * takes in APCu. The rules go straight into the stream, a tag each; one pool
 * loads and shares them, then fresh pools adopt the set and read one item.
 * The 0-rule row is the floor: what a fresh pool and a read cost anyway.
 *
 * @param list<int>             $sizes
 * @param array<string, string> $client
 */
function adopt(string $dsn, array $client, array $sizes, int $db, bool $force): void
{
    if (!SharedRules::apcuEnabled()) {
        fwrite(\STDERR, '! adopt= needs APCu in this SAPI: run with -d apc.enable_cli=1.'.\PHP_EOL);

        exit(1);
    }

    $connection = RedisAdapter::createConnection($dsn.'/'.$db, $client);

    if (!$connection instanceof Redis && !$connection instanceof Relay\Relay) {
        fwrite(\STDERR, 'adopt= needs a single node.'.\PHP_EOL);

        exit(1);
    }

    if ($connection->dbSize() > 0 && !$force) {
        fwrite(\STDERR, sprintf('! database %d is not empty. Point db= somewhere empty, or pass force=1.'.\PHP_EOL, $db));

        exit(1);
    }

    $pools = 200;
    $rows = [];

    foreach ([0, ...$sizes] as $size) {
        foreach (0 === $size ? [15_000] : [15_000, 0] as $bound) {
            $connection->flushDb();
            $shared = SharedRules::of(null, 'adopt:@rules', $connection);
            $shared?->forget();

            for ($i = 0; $i < $size; $i += 5_000) {
                $pipe = $connection->multi(Redis::PIPELINE);

                for ($j = $i; $j < min($size, $i + 5_000); ++$j) {
                    $pipe->xAdd('adopt:@rules', '*', ['t' => json_encode(['t' => ['entity-'.$j]]), 'first' => 0 === $j ? '1' : '0']);
                }

                $pipe->exec();
            }

            $make = static fn (): VersionedRedisTagAwareAdapter => new VersionedRedisTagAwareAdapter($connection, 'adopt', rulesCacheMs: 600_000, rulesMaxTags: $bound);

            $began = microtime(true);
            $make()->getItem('x');
            $load = microtime(true) - $began;

            $began = microtime(true);

            for ($p = 0; $p < $pools; ++$p) {
                $make()->getItem('x');
            }

            $perPool = (microtime(true) - $began) / $pools;
            $bytes = 0;

            foreach (new APCUIterator('/^artigo\.versioned\./', \APC_ITER_MEM_SIZE) as $entry) {
                $bytes += (int) $entry['mem_size'];
            }

            $label = 0 === $size ? '0 rules' : sprintf('%s rules, %s', number_format($size, 0, '.', ' '), 0 === $bound ? 'no bound' : 'bound '.number_format($bound, 0, '.', ' '));
            $rows[] = [$label, $load, $perPool, $bytes];

            echo sprintf('  %-34s load %8.1f ms   fresh pool %7.3f ms   APCu %8s KB%s', $label, $load * 1000, $perPool * 1000, number_format($bytes / 1024), \PHP_EOL);
        }
    }

    $connection->flushDb();

    echo \PHP_EOL.'| rule set | first load from the stream | a fresh pool adopting it, and one read | in APCu |'.\PHP_EOL;
    echo '|---|---:|---:|---:|'.\PHP_EOL;

    foreach ($rows as [$label, $load, $perPool, $bytes]) {
        echo sprintf('| %s | %.1f ms | %.2f ms | %s KB |'.\PHP_EOL, $label, $load * 1000, $perPool * 1000, number_format($bytes / 1024));
    }
}

/**
 * Round trips per request reading $count keys, counted the way bastnic's
 * lab:read counts them: socket reads on the server, summed over every node,
 * replicas included. Each request is a fresh pool, as under PHP-FPM; the
 * versioned pool adopts the rule set the previous request shared, as every
 * request within rulesCacheMs of a refresh does.
 *
 * @param array<string, string> $client
 * @param list<string>          $stores
 */
function reads(string $dsn, array $client, int $count, array $stores, ?MarshallerInterface $marshaller): void
{
    $connection = RedisAdapter::createConnection($dsn, $client + ['lazy' => false]);
    $nodes = nodes($connection);
    $keys = [];

    for ($i = 0; $i < $count; ++$i) {
        $keys[] = 'k'.$i;
    }

    // tiny values, as lab:read has them, so that one burst is one socket read
    // and the reads counted are the round trips; a namespace per store, so
    // that one store's clear() leaves the others alone
    $value = 'v';
    // TagAwareAdapter with every tag version in one cluster slot, only when
    // asked for (stores=tagaware-slot): it needs a connection of its own
    $slotted = in_array('tagaware-slot', $stores, true) ? slotted($dsn) : null;
    $makers = [
        'versioned' => static fn (): AdapterInterface => new VersionedRedisTagAwareAdapter($connection, 'rv', marshaller: $marshaller),
        'symfony' => static fn (): AdapterInterface => new RedisTagAwareAdapter($connection, 'rs', 0, $marshaller),
        'tagaware' => static fn (): AdapterInterface => new TagAwareAdapter(new RedisAdapter($connection, 'rt', 0, $marshaller)),
        'redis' => static fn (): AdapterInterface => new RedisAdapter($connection, 'rr', 0, $marshaller),
        'tagaware-slot' => static fn (): AdapterInterface => new TagAwareAdapter(new RedisAdapter($connection, 'rq', 0, $marshaller), new RedisAdapter($slotted ?? slotted($dsn), 'rq', 0, $marshaller)),
    ];

    $strategies = [
        'get() loop' => static function (AdapterInterface $pool) use ($keys, $value): void {
            // a pool that is not tag-aware refuses a tag on its items
            $tagged = $pool instanceof TagAwareAdapterInterface;

            foreach ($keys as $i => $key) {
                assert($pool instanceof CacheInterface);
                $pool->get($key, static function (ItemInterface $item) use ($i, $value, $tagged): string {
                    if ($tagged) {
                        $item->tag('t'.intdiv($i, 10));
                    }

                    return $value;
                });
            }
        },
        'getItems() + batch' => static function (AdapterInterface $pool) use ($keys, $value): void {
            $missed = false;

            foreach ($pool->getItems($keys) as $key => $item) {
                if ($item->isHit()) {
                    continue;
                }

                $item->set($value);

                if ($pool instanceof TagAwareAdapterInterface) {
                    $item->tag('t'.intdiv((int) substr((string) $key, 1), 10));
                }

                $pool->saveDeferred($item);
                $missed = true;
            }

            if ($missed) {
                $pool->commit();
            }
        },
    ];

    echo sprintf('%s | %d keys | %d node%s'.\PHP_EOL, $connection instanceof RedisCluster ? 'cluster' : 'standalone', $count, count($nodes), 1 === count($nodes) ? '' : 's');
    echo '| strategy | warm | cold |'.\PHP_EOL.'|---|---:|---:|'.\PHP_EOL;

    foreach ($strategies as $strategy => $request) {
        foreach ($stores === ['versioned', 'symfony', 'tagaware'] ? ['versioned', 'symfony', 'tagaware', 'redis'] : $stores as $store) {
            $make = $makers[$store] ?? throw new RuntimeException(sprintf('Unknown store "%s".', $store));

            // a rule in the stream, so the versioned pools have a set to share
            $make()->clear();
            $request($make());

            // warm: every key there; the request before this one left the
            // shared rule set fresh
            $request($make());
            $warm = roundTrips($nodes, static fn () => $request($make()));

            // cold: every key gone
            $make()->deleteItems($keys);
            $cold = roundTrips($nodes, static fn () => $request($make()));

            echo sprintf('| %s, `%s` | %d | %d |'.\PHP_EOL, $strategy, $store, $warm, $cold);
        }
    }

    foreach ($makers as $make) {
        $make()->clear();
    }
}

/**
 * Every node of the connection on a connection of its own: one for a single
 * node, the masters for a cluster, and their replicas where reads go to them.
 *
 * @return list<Redis>
 */
function nodes(object $connection): array
{
    if ($connection instanceof Redis) {
        $node = new Redis();
        $node->connect($connection->getHost(), $connection->getPort());

        return [$node];
    }

    if (!$connection instanceof RedisCluster) {
        throw new RuntimeException('reads= needs ext-redis, standalone or cluster.');
    }

    $nodes = [];

    foreach ($connection->_masters() as $master) {
        [$host, $port] = is_array($master) ? $master : explode(':', (string) $master);
        $node = new Redis();
        $node->connect((string) $host, (int) $port);
        $nodes[] = $node;

        // a replica reads its primary's replication stream too: counted only
        // where clients read from it, and then its reads are not all theirs
        if (RedisCluster::FAILOVER_NONE === $connection->getOption(RedisCluster::OPT_SLAVE_FAILOVER)) {
            continue;
        }

        $replication = $node->info('replication');

        foreach ($replication as $field => $line) {
            if (str_starts_with((string) $field, 'slave') && preg_match('/ip=([^,]+),port=(\d+)/', (string) $line, $match)) {
                $replica = new Redis();
                $replica->connect('127.0.0.1' === $match[1] || '::1' === $match[1] ? $match[1] : (string) $host, (int) $match[2]);
                $nodes[] = $replica;
            }
        }
    }

    return $nodes;
}

/**
 * Socket reads the nodes did during $work, less the one each INFO after it
 * costs.
 *
 * @param list<Redis> $nodes
 */
function roundTrips(array $nodes, Closure $work): int
{
    $read = static function () use ($nodes): array {
        $counts = [];

        foreach ($nodes as $node) {
            $counts['reads'] = ($counts['reads'] ?? 0) + (int) ($node->info('stats')['total_reads_processed'] ?? 0);

            foreach ((array) $node->info('commandstats') as $name => $line) {
                if (preg_match('/calls=(\d+)/', (string) $line, $match)) {
                    $counts[(string) $name] = ($counts[(string) $name] ?? 0) + (int) $match[1];
                }
            }
        }

        return $counts;
    };

    $before = $read();
    $work();
    $after = $read();

    if (getenv('BENCH_DEBUG')) {
        foreach ($after as $name => $count) {
            if ($count !== ($before[$name] ?? 0)) {
                fwrite(\STDERR, sprintf('    %s +%d'.\PHP_EOL, $name, $count - ($before[$name] ?? 0)));
            }
        }
    }

    // the INFO calls after the work are one read per node
    return $after['reads'] - $before['reads'] - 2 * count($nodes);
}

/**
 * @param list<Result> $results
 */
function table(array $results): void
{
    $scenarios = [];
    $byStore = [];

    foreach ($results as $result) {
        $scenarios[$result->scenario] = true;
        $byStore[$result->store][$result->scenario] = $result;
    }

    $names = array_keys($byStore);

    echo '| Scenario | '.implode(' | ', $names).' |'.\PHP_EOL;
    echo '|---|'.str_repeat('---|', count($names)).\PHP_EOL;

    foreach (array_keys($scenarios) as $scenario) {
        $cells = [];

        foreach ($names as $store) {
            $result = $byStore[$store][$scenario] ?? null;

            if (null === $result) {
                $cells[] = '&mdash;';

                continue;
            }

            $cells[] = null !== $result->note
                ? $result->note
                : sprintf('%.3f s<br>%s cmds · %s reads',
                    $result->seconds,
                    number_format($result->commands),
                    number_format($result->reads),
                );
        }

        echo '| `'.$scenario.'` | '.implode(' | ', $cells).' |'.\PHP_EOL;
    }

    echo \PHP_EOL;
}

/**
 * A connection of its own for TagAwareAdapter's tag versions, putting the
 * hash tag {tags} in front of every key, so that every version lands in one
 * cluster slot (see ban.php).
 */
function slotted(string $dsn): object
{
    $connection = RedisAdapter::createConnection($dsn, ['lazy' => false]);
    $connection->setOption(Redis::OPT_PREFIX, '{tags}');

    return $connection;
}
