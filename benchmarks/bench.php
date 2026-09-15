<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * What does a tag cost when it falls?
 *
 * Runs this adapter and Symfony's RedisTagAwareAdapter through the same
 * scenarios - filling, overwriting, reading, invalidating at three fan-out
 * sizes, reading again, and giving the memory back - and prints a markdown
 * table.
 *
 * Every row carries the command count and the number of socket reads Redis
 * did, because those do not move when the network does. The wall clock beside
 * them is worth exactly as much as the link it was measured over.
 *
 *   docker compose up -d
 *   php benchmarks/bench.php
 *   php benchmarks/bench.php items=50000 tags=20
 *   php benchmarks/bench.php stores=versioned
 *
 * Options: dsn=, items=, tags=, db=, stores=, client=relay, force=1
 *
 * Each store gets its own Redis database and must find it empty; pass force=1
 * to flush whatever is there instead. The counters this reads are server-wide,
 * so nothing else may be talking to that Redis while it runs.
 */

use Artigo\Cache\Adapter\VersionedRedisTagAwareAdapter;
use Artigo\Cache\Benchmarks\Meter;
use Artigo\Cache\Benchmarks\Result;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;

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
$stores = explode(',', (string) ($options['stores'] ?? 'versioned,symfony'));

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

    $pool = 'versioned' === $name
        ? new VersionedRedisTagAwareAdapter($connection, 'bench')
        : new RedisTagAwareAdapter($connection, 'bench');

    echo sprintf('%s%s', $name, \PHP_EOL);

    try {
        run($meter, $pool, $name, $items, $tagCount, $fanouts);
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
    TagAwareAdapterInterface $pool,
    string $name,
    int $items,
    int $tagCount,
    array $fanouts,
): void {
    // an untimed pass first, so class loading and script caching do not land
    // on whichever scenario happens to run first
    fill($pool, 50, $tagCount);
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

    $report($meter->measure($name, 'fill', static fn () => fill($pool, $items, $tagCount)));
    $report($meter->measure($name, 'overwrite', static fn () => fill($pool, $items, $tagCount)));
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
    fill($pool, $items, $tagCount);

    $report($meter->measure($name, 'invalidate 10 000 unrelated tags', static function () use ($pool): void {
        for ($i = 0; $i < 10_000; ++$i) {
            $pool->invalidateTags(['nobody-'.$i]);
        }
    }));
    $report($meter->measure($name, 'read hit, 10 000 rules behind', static fn () => read($pool, $items)));
}

function fill(TagAwareAdapterInterface $pool, int $items, int $tagCount): void
{
    $payload = str_repeat('x', 256);

    for ($i = 0; $i < $items; ++$i) {
        $item = $pool->getItem('item-'.$i);
        $item->set($payload);
        $item->expiresAfter(3600);
        $item->tag(tagsFor($i, $tagCount, $items));
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
 * carries "tenth", which is what makes the three fan-outs.
 *
 * @return list<string>
 */
function tagsFor(int $index, int $tagCount, int $items): array
{
    $tags = ['everything', 'bucket-'.($index % 20)];

    if (0 === $index % 10) {
        $tags[] = 'tenth';
    }

    for ($t = count($tags); $t < $tagCount; ++$t) {
        $tags[] = sprintf('filler-%d-%d', $t, $index % 97);
    }

    return $tags;
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
