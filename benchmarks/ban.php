<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Banning thousands of tags: what it costs the server, and everybody else on
 * it while it happens.
 *
 * Populates N tags x M entries through each adapter's public API (one tag per
 * entry, ~100-byte values), then invalidates every tag in chunks of 50 - the
 * shape of a message handler that bans after a bulk change - while a second
 * process sends PING to every primary in a loop. A slow PING is a server busy
 * with something else. The format follows bastnic's lab:ban (the gist behind
 * symfony/symfony#67391), so the rows can be read beside his.
 *
 *   docker compose up -d
 *   php benchmarks/ban.php tags=1000 entries=1000
 *   php benchmarks/ban.php tags=5000 entries=200
 *   php benchmarks/ban.php dsn="redis:?host[127.0.0.1:7110]&host[127.0.0.1:7111]&host[127.0.0.1:7112]&redis_cluster=1" tags=1000 entries=200
 *   php benchmarks/ban.php dsn="redis:?host[127.0.0.1:7110]&host[127.0.0.1:7111]&host[127.0.0.1:7112]&redis_cluster=1" tags=1000 entries=200 stores=tagaware,tagaware-slot
 *
 * Options: dsn=, tags=, entries=, chunk= (50), stores= (versioned,symfony,tagaware;
 *          tagaware-slot is TagAwareAdapter with every tag version in one
 *          cluster slot, see slotted()),
 *          bytes= (value size, 100), force=1 (flush a non-empty database)
 *
 * Every primary is flushed before and after each store, so point it at a
 * Redis that holds nothing else. Commands are counted on the primaries, less
 * the probe's PINGs and this script's own INFO calls.
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

// the probe: a second process pinging every primary until told to stop
if (isset($options['probe'])) {
    probe(explode(',', (string) $options['probe']), (string) $options['stop'], (string) $options['out'], (string) $options['ready']);

    exit(0);
}

$dsn = (string) ($options['dsn'] ?? getenv('REDIS_DSN') ?: 'redis://127.0.0.1:6379');
$tagCount = (int) ($options['tags'] ?? 1000);
$entries = (int) ($options['entries'] ?? 1000);
$chunk = max(1, (int) ($options['chunk'] ?? 50));
$bytes = max(1, (int) ($options['bytes'] ?? 100));
$force = (bool) ($options['force'] ?? false);
$stores = array_values(array_filter(array_map(trim(...), explode(',', (string) ($options['stores'] ?? 'versioned,symfony,tagaware')))));

$connection = RedisAdapter::createConnection($dsn, ['lazy' => false]);
$primaries = primaries($connection);
$cluster = $connection instanceof RedisCluster;

foreach ($primaries as $address => $node) {
    $size = $node->dbSize();

    if ($size > 0 && !$force) {
        fwrite(\STDERR, sprintf('! %s holds %d keys. Point dsn= somewhere empty, or pass force=1.'.\PHP_EOL, $address, $size));

        exit(1);
    }
}

$server = reset($primaries)->info('server');

echo sprintf('%s | %s tags x %s entries | chunks of %d | %d-byte values | %s %s | %d primar%s%s',
    $cluster ? 'cluster' : 'standalone',
    number_format($tagCount),
    number_format($entries),
    $chunk,
    $bytes,
    str_contains((string) ($server['redis_version'] ?? ''), 'valkey') ? 'Valkey' : 'Redis',
    $server['redis_version'] ?? '?',
    count($primaries),
    1 === count($primaries) ? 'y' : 'ies',
    \PHP_EOL,
);

$rows = [];

foreach ($stores as $name) {
    flushAll($primaries);

    $pool = match ($name) {
        'versioned' => new VersionedRedisTagAwareAdapter($connection, 'ban', rulesCache: false),
        'symfony' => new RedisTagAwareAdapter($connection, 'ban'),
        'tagaware' => new TagAwareAdapter(new RedisAdapter($connection, 'ban')),
        'tagaware-slot' => new TagAwareAdapter(new RedisAdapter($connection, 'ban'), new RedisAdapter(slotted($dsn), 'ban')),
        default => throw new RuntimeException(sprintf('Unknown store "%s"; pick from versioned, symfony, tagaware, tagaware-slot.', $name)),
    };

    $began = microtime(true);
    populate($pool, $tagCount, $entries, $bytes);
    $populated = microtime(true) - $began;
    $before = memory($primaries);

    $rows[$name] = ban($pool, $primaries, $tagCount, $chunk) + ['populated' => $populated, 'memory before' => $before];

    echo sprintf('  %-10s populated in %.1f s, banned in %s, %s commands, PING p50 %.2f / p99 %.2f / max %.2f ms, memory %s -> %s'.\PHP_EOL,
        $name,
        $populated,
        duration($rows[$name]['seconds']),
        number_format($rows[$name]['commands']),
        $rows[$name]['p50'],
        $rows[$name]['p99'],
        $rows[$name]['max'],
        megabytes($before),
        megabytes($rows[$name]['memory after']),
    );
}

flushAll($primaries);

echo \PHP_EOL.'| stack | ban duration | commands on the primaries | `PING` p50 / p99 / max during the ban | memory after the ban |'.\PHP_EOL;
echo '|---|---:|---|---:|---|'.\PHP_EOL;

foreach ($rows as $name => $row) {
    echo sprintf('| %s | %s | %s | %.2f / %.2f / %.2f ms | %s (%s before) |'.\PHP_EOL,
        match ($name) {
            'versioned' => '`VersionedRedisTagAwareAdapter`',
            'symfony' => '`RedisTagAwareAdapter`',
            'tagaware-slot' => '`TagAwareAdapter`, every tag version in one slot',
            default => '`TagAwareAdapter`',
        },
        duration($row['seconds']),
        $row['commands'] > 0 ? number_format($row['commands']).' ('.$row['top'].')' : '0',
        $row['p50'],
        $row['p99'],
        $row['max'],
        megabytes($row['memory after']),
        megabytes($row['memory before']),
    );
}

/**
 * Writes every entry through the pool's public API: a batch of keys is read
 * once with getItems(), then saved deferred and committed, the way a batch
 * loader fills a cache.
 */
function populate(TagAwareAdapterInterface $pool, int $tagCount, int $entries, int $bytes): void
{
    $value = str_repeat('v', $bytes);
    $keys = [];

    for ($t = 0; $t < $tagCount; ++$t) {
        for ($e = 0; $e < $entries; ++$e) {
            $keys[] = 'e'.$t.'.'.$e;

            if (1000 === count($keys)) {
                write($pool, $keys, $value);
                $keys = [];
            }
        }
    }

    if ([] !== $keys) {
        write($pool, $keys, $value);
    }
}

/**
 * @param list<string> $keys
 */
function write(TagAwareAdapterInterface $pool, array $keys, string $value): void
{
    foreach ($pool->getItems($keys) as $key => $item) {
        $item->set($value);
        $item->tag('t'.strstr(substr((string) $key, 1), '.', true));
        $item->expiresAfter(3600);
        $pool->saveDeferred($item);
    }

    if (!$pool->commit()) {
        throw new RuntimeException('The pool refused part of the batch.');
    }
}

/**
 * Invalidates every tag in chunks while the probe pings, and reads the
 * primaries' counters around it.
 *
 * @param array<string, Redis> $primaries
 *
 * @return array{seconds: float, commands: int, top: string, p50: float, p99: float, max: float, 'memory after': int}
 */
function ban(TagAwareAdapterInterface $pool, array $primaries, int $tagCount, int $chunk): array
{
    $dir = sys_get_temp_dir().\DIRECTORY_SEPARATOR.'versioned-ban-'.getmypid();
    @mkdir($dir);
    $stop = $dir.\DIRECTORY_SEPARATOR.'stop';
    $out = $dir.\DIRECTORY_SEPARATOR.'pings.json';
    $ready = $dir.\DIRECTORY_SEPARATOR.'ready';
    @unlink($stop);
    @unlink($out);
    @unlink($ready);

    $probe = proc_open([\PHP_BINARY, __FILE__, 'probe='.implode(',', array_keys($primaries)), 'stop='.$stop, 'out='.$out, 'ready='.$ready], [], $pipes);

    if (!is_resource($probe)) {
        throw new RuntimeException('The PING probe would not start.');
    }

    // a ban can be over in milliseconds: start it only once the probe is
    // pinging, and give it a moment in its loop
    $deadline = microtime(true) + 10;

    while (!file_exists($ready) && microtime(true) < $deadline) {
        usleep(10_000);
    }

    usleep(100_000);

    $statsBefore = stats($primaries);
    $began = microtime(true);

    for ($t = 0; $t < $tagCount; $t += $chunk) {
        $tags = [];

        for ($i = $t; $i < min($tagCount, $t + $chunk); ++$i) {
            $tags[] = 't'.$i;
        }

        $pool->invalidateTags($tags);
    }

    $ended = microtime(true);
    $statsAfter = stats($primaries);

    touch($stop);
    proc_close($probe);

    $pings = json_decode((string) file_get_contents($out), true);
    @unlink($stop);
    @unlink($out);
    @unlink($ready);
    @rmdir($dir);

    // the PINGs sent while the ban ran, in milliseconds
    $during = [];

    foreach (is_array($pings) ? $pings : [] as [$at, $ms]) {
        if ($at >= $began && $at <= $ended) {
            $during[] = (float) $ms;
        }
    }

    sort($during);

    [$commands, $top] = commandsBetween($statsBefore, $statsAfter);

    return [
        'seconds' => $ended - $began,
        'commands' => $commands,
        'top' => $top,
        'p50' => percentile($during, 0.50),
        'p99' => percentile($during, 0.99),
        'max' => [] === $during ? 0.0 : end($during),
        'memory after' => memory($primaries),
    ];
}

/**
 * @param list<string> $addresses
 */
function probe(array $addresses, string $stop, string $out, string $ready): void
{
    $nodes = [];

    foreach ($addresses as $address) {
        [$host, $port] = explode(':', $address);
        $node = new Redis();
        $node->connect($host, (int) $port);
        $nodes[] = $node;
    }

    $pings = [];

    while (!file_exists($stop)) {
        foreach ($nodes as $node) {
            $began = hrtime(true);
            $node->ping();
            $pings[] = [microtime(true), (hrtime(true) - $began) / 1e6];
        }

        if (!file_exists($ready)) {
            touch($ready);
        }
    }

    file_put_contents($out, json_encode($pings));
}

/**
 * The primaries, each reached on its own connection: the counters and the
 * memory are per node.
 *
 * @return array<string, Redis>
 */
function primaries(object $connection): array
{
    $addresses = [];

    if ($connection instanceof RedisCluster) {
        foreach ($connection->_masters() as $master) {
            $addresses[] = is_array($master) ? $master[0].':'.$master[1] : (string) $master;
        }
    } elseif ($connection instanceof Redis) {
        $addresses[] = $connection->getHost().':'.$connection->getPort();
    } else {
        throw new RuntimeException('This benchmark needs an ext-redis connection or cluster.');
    }

    $nodes = [];

    foreach ($addresses as $address) {
        [$host, $port] = explode(':', $address);
        $node = new Redis();
        $node->connect($host, (int) $port);
        $nodes[$address] = $node;
    }

    return $nodes;
}

/**
 * @param array<string, Redis> $primaries
 */
function flushAll(array $primaries): void
{
    foreach ($primaries as $node) {
        $node->flushDb();
    }
}

/**
 * @param array<string, Redis> $primaries
 */
function memory(array $primaries): int
{
    $used = 0;

    foreach ($primaries as $node) {
        $memory = $node->info('memory');
        $used += (int) ($memory['used_memory'] ?? 0);
    }

    return $used;
}

/**
 * Every primary's command counters: the total, and per command.
 *
 * @param array<string, Redis> $primaries
 *
 * @return array<string, int>
 */
function stats(array $primaries): array
{
    $counts = [];

    foreach ($primaries as $node) {
        foreach ((array) $node->info('commandstats') as $name => $line) {
            if (preg_match('/calls=(\d+)/', (string) $line, $match)) {
                $command = strtoupper(substr((string) $name, strlen('cmdstat_')));
                $counts[$command] = ($counts[$command] ?? 0) + (int) $match[1];
            }
        }
    }

    return $counts;
}

/**
 * What the server ran between two snapshots, less the probe's PINGs and the
 * snapshots themselves: the total, and the three commands it ran most.
 *
 * @param array<string, int> $before
 * @param array<string, int> $after
 *
 * @return array{int, string}
 */
function commandsBetween(array $before, array $after): array
{
    $delta = [];

    foreach ($after as $command => $calls) {
        if (in_array($command, ['PING', 'INFO'], true)) {
            continue;
        }

        if (0 < $calls - ($before[$command] ?? 0)) {
            $delta[$command] = $calls - ($before[$command] ?? 0);
        }
    }

    arsort($delta);
    $top = [];

    foreach (array_slice($delta, 0, 3, true) as $command => $calls) {
        $top[] = number_format($calls).' '.$command;
    }

    return [array_sum($delta), implode(', ', $top)];
}

/**
 * @param list<float> $sorted
 */
function percentile(array $sorted, float $at): float
{
    if ([] === $sorted) {
        return 0.0;
    }

    return $sorted[min(count($sorted) - 1, (int) floor($at * count($sorted)))];
}

function duration(float $seconds): string
{
    return $seconds < 1 ? sprintf('%d ms', (int) round($seconds * 1000)) : sprintf('%.1f s', $seconds);
}

function megabytes(int $bytes): string
{
    return sprintf('%.1f MB', $bytes / 1048576);
}

/**
 * A connection of its own for TagAwareAdapter's tag versions, putting the
 * hash tag {tags} in front of every key: a cluster hashes only what is inside
 * the braces, so every version lands in one slot, and invalidating a chunk of
 * tags is one UNLINK instead of one per slot. Cache keys may not hold braces,
 * so the client's prefix is the only way to put them there.
 */
function slotted(string $dsn): object
{
    $connection = RedisAdapter::createConnection($dsn, ['lazy' => false]);
    $connection->setOption(Redis::OPT_PREFIX, '{tags}');

    return $connection;
}
