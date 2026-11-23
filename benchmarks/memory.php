<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * What does an item cost in Redis memory, and what does a rule cost?
 *
 * Writes one item through each adapter - this one, Symfony's
 * RedisTagAwareAdapter and Symfony's TagAwareAdapter over a RedisAdapter - the
 * way ban.php fills a pool (getItems(), then saveDeferred() and commit(); one
 * tag, a value of N bytes), and reads MEMORY USAGE of the item's key at
 * hash-max-listpack-value 64, the default, and 4096. This adapter's item is
 * then taken apart: what the guard's epoch adds (the same item written with
 * guardWindowSeconds: 0), what the expiry on its fields adds (the same fields
 * written again without one), and what a delete leaves for the guard window
 * (the tombstone). Last, the rules stream: N invalidations of one tag each,
 * the bytes the stream holds per rule, and the bytes a fresh pool with no
 * shared rule set receives per rule when it loads them.
 *
 *   docker compose up -d
 *   php benchmarks/memory.php
 *   php benchmarks/memory.php bytes=100,2600 limits=64,4096 rules=10000
 *
 * Options: dsn=, db= (15), bytes= (100,2600), limits= (64,4096),
 *          rules= (10000), force=1 (flush a non-empty database)
 *
 * It changes hash-max-listpack-value with CONFIG SET and puts the server's
 * own value back when it ends, so point it at a Redis that holds nothing else.
 * MEMORY USAGE is read with SAMPLES 0, so every field is counted; it leaves
 * the allocator's overhead out, which is why ban.php reads used_memory for a
 * million items instead.
 */

use Artigo\Cache\Adapter\VersionedRedisTagAwareAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

require __DIR__.'/../vendor/autoload.php';

$options = [];

foreach (array_slice($argv, 1) as $argument) {
    if (str_contains($argument, '=')) {
        [$name, $value] = explode('=', $argument, 2);
        $options[$name] = $value;
    }
}

$dsn = (string) ($options['dsn'] ?? getenv('REDIS_DSN') ?: 'redis://127.0.0.1:6379');
$db = (int) ($options['db'] ?? 15);
$sizes = array_map(intval(...), explode(',', (string) ($options['bytes'] ?? '100,2600')));
$limits = array_map(intval(...), explode(',', (string) ($options['limits'] ?? '64,4096')));
$ruleCount = max(1, (int) ($options['rules'] ?? 10_000));
$force = (bool) ($options['force'] ?? false);

// every key the pools write is this namespace and one of these ids
const KEY = 'mem:item';

$connection = RedisAdapter::createConnection($dsn.'/'.$db, ['lazy' => false]);

if (!$connection instanceof Redis) {
    fwrite(\STDERR, 'This benchmark needs a single ext-redis node.'.\PHP_EOL);

    exit(1);
}

$size = $connection->dbSize();

if ($size > 0 && !$force) {
    fwrite(\STDERR, sprintf('! database %d holds %d keys. Point db= somewhere empty, or pass force=1.'.\PHP_EOL, $db, $size));

    exit(1);
}

$server = $connection->info('server');
$original = $connection->config('GET', 'hash-max-listpack-value');
$original = is_array($original) ? (string) ($original['hash-max-listpack-value'] ?? '64') : '64';

echo sprintf('%s | Redis %s | hash-max-listpack-value %s on the server%s', $dsn, $server['redis_version'] ?? '?', $original, \PHP_EOL);

$items = [];
$parts = [];

try {
    foreach ($limits as $limit) {
        $connection->config('SET', 'hash-max-listpack-value', (string) $limit);

        foreach ($sizes as $bytes) {
            $value = str_repeat('v', $bytes);

            foreach (['versioned', 'symfony', 'tagaware'] as $store) {
                $connection->flushDb();
                write(pool($store, $connection), $value);
                $items[$bytes][$store][$limit] = usage($connection);
            }

            // the same item without the guard: no epoch field
            $connection->flushDb();
            write(pool('versioned', $connection, 0), $value);
            $withoutEpoch = usage($connection);

            // the guarded item's fields written again with no expiry of their
            // own, under the same key and the same key TTL
            $connection->flushDb();
            write(pool('versioned', $connection), $value);
            $fields = $connection->hGetAll(KEY);
            $ttl = $connection->pttl(KEY);
            $connection->del(KEY);
            $connection->hMSet(KEY, $fields);
            $connection->pExpire(KEY, $ttl);
            $withoutExpiry = usage($connection);

            // what a delete leaves for the guard window
            $connection->flushDb();
            $pool = pool('versioned', $connection);
            write($pool, $value);
            $pool->deleteItem('item');
            $tombstone = usage($connection);

            $item = $items[$bytes]['versioned'][$limit];
            $parts[] = [$limit, $bytes, $item, $withoutEpoch, $withoutExpiry, $tombstone];

            echo sprintf('  limit %4d, %4d-byte value: item %s B (%s), without the epoch %s B, without field expiry %s B (%s), tombstone %s B (%s)%s',
                $limit,
                $bytes,
                number_format($item[0]),
                $item[1],
                number_format($withoutEpoch[0]),
                number_format($withoutExpiry[0]),
                $withoutExpiry[1],
                number_format($tombstone[0]),
                $tombstone[1],
                \PHP_EOL,
            );
        }
    }

    // the rules stream: one tag per invalidation, the worst case for its size
    $connection->config('SET', 'hash-max-listpack-value', $original);
    $connection->flushDb();
    $pool = new VersionedRedisTagAwareAdapter($connection, 'mem', rulesCache: false);

    for ($i = 0; $i < $ruleCount; ++$i) {
        $pool->invalidateTags(['entity-'.$i]);
    }

    $stream = streamKey($connection);
    $held = (int) $connection->rawCommand('MEMORY', 'USAGE', $stream, 'SAMPLES', '0');

    // what the server sends a fresh pool that loads the stream, less the reply
    // to the INFO before it, which the INFO after it counts
    $first = sent($connection);
    $overhead = sent($connection) - $first;
    $before = sent($connection);
    (new VersionedRedisTagAwareAdapter($connection, 'mem', rulesCache: false))->getItem('item');
    $loaded = sent($connection) - $before - $overhead;
} finally {
    $connection->config('SET', 'hash-max-listpack-value', $original);
    $connection->flushDb();
}

echo \PHP_EOL.'| value | **versioned**, limit '.implode(' | **versioned**, limit ', $limits).' | `RedisTagAwareAdapter` | `TagAwareAdapter` |'.\PHP_EOL;
echo '|---|'.str_repeat('---:|', count($limits) + 2).\PHP_EOL;

foreach ($items as $bytes => $stores) {
    $cells = [];

    foreach ($limits as $limit) {
        $cells[] = sprintf('%s B (`%s`)', number_format($stores['versioned'][$limit][0], 0, '.', ' '), $stores['versioned'][$limit][1]);
    }

    // a string does not care about the hash limit: the first limit's reading
    foreach (['symfony', 'tagaware'] as $store) {
        $cells[] = sprintf('%s B', number_format($stores[$store][$limits[0]][0], 0, '.', ' '));
    }

    echo sprintf('| %s B | %s |'.\PHP_EOL, number_format($bytes, 0, '.', ' '), implode(' | ', $cells));
}

echo \PHP_EOL.'| limit | value | the item | the epoch | the expiry on its fields | a tombstone |'.\PHP_EOL;
echo '|---:|---:|---:|---:|---:|---:|'.\PHP_EOL;

foreach ($parts as [$limit, $bytes, $item, $withoutEpoch, $withoutExpiry, $tombstone]) {
    echo sprintf('| %d | %s B | %s B (`%s`) | %+d B | %+d B | %s B (`%s`) |'.\PHP_EOL,
        $limit,
        number_format($bytes, 0, '.', ' '),
        number_format($item[0], 0, '.', ' '),
        $item[1],
        $item[0] - $withoutEpoch[0],
        $item[0] - $withoutExpiry[0],
        number_format($tombstone[0], 0, '.', ' '),
        $tombstone[1],
    );
}

echo \PHP_EOL.sprintf('| rules, one tag each | the stream in memory | per rule | a fresh pool loading them, bytes received | per rule |%s|---:|---:|---:|---:|---:|%s| %s | %s B | %.0f B | %s B | %.0f B |%s',
    \PHP_EOL,
    \PHP_EOL,
    number_format($ruleCount, 0, '.', ' '),
    number_format($held, 0, '.', ' '),
    $held / $ruleCount,
    number_format($loaded, 0, '.', ' '),
    $loaded / $ruleCount,
    \PHP_EOL,
);

function pool(string $store, Redis $connection, int $guardWindowSeconds = 60): TagAwareAdapterInterface
{
    return match ($store) {
        'versioned' => new VersionedRedisTagAwareAdapter($connection, 'mem', rulesCache: false, guardWindowSeconds: $guardWindowSeconds),
        'symfony' => new RedisTagAwareAdapter($connection, 'mem'),
        'tagaware' => new TagAwareAdapter(new RedisAdapter($connection, 'mem')),
        default => throw new RuntimeException(sprintf('Unknown store "%s".', $store)),
    };
}

/**
 * One item, written as ban.php writes its entries: read first, then saved
 * deferred and committed, so this adapter's write is the one that follows a
 * read.
 */
function write(TagAwareAdapterInterface $pool, string $value): void
{
    foreach ($pool->getItems(['item']) as $item) {
        $item->set($value);
        $item->tag('t1');
        $item->expiresAfter(3600);
        $pool->saveDeferred($item);
    }

    if (!$pool->commit()) {
        throw new RuntimeException('The pool refused the item.');
    }
}

/**
 * MEMORY USAGE of the item's key, every field counted, and its encoding.
 *
 * @return array{int, string}
 */
function usage(Redis $connection): array
{
    if (!$connection->exists(KEY)) {
        throw new RuntimeException(sprintf('No key "%s": the adapters name their keys differently than this script assumes.', KEY));
    }

    return [
        (int) $connection->rawCommand('MEMORY', 'USAGE', KEY, 'SAMPLES', '0'),
        (string) $connection->rawCommand('OBJECT', 'ENCODING', KEY),
    ];
}

function streamKey(Redis $connection): string
{
    $answer = $connection->rawCommand('SCAN', '0', 'COUNT', '1000', 'TYPE', 'stream');

    if (!is_array($answer) || !isset($answer[1][0])) {
        throw new RuntimeException('The invalidations left no stream.');
    }

    return (string) $answer[1][0];
}

/**
 * Bytes the server has sent to every client so far.
 */
function sent(Redis $connection): int
{
    $stats = $connection->info('stats');

    return (int) ($stats['total_net_output_bytes'] ?? 0);
}
