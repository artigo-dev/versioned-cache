<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Does a value computed before an invalidation survive it?
 *
 * One process keeps a source value in Redis and, every round, sets it, makes
 * the cached item a miss, waits a random part of a computation, changes the
 * source and invalidates again - its tag, or the key - then reads the item
 * once every computation that started before the change has written. Other
 * processes compute that miss all the while, through get(), with a callback
 * that reads the source, takes its time and declares the tag. A round counts
 * when the read is a hit on the old value: a computation that read the
 * source before the invalidation wrote it back after, and nobody can tell.
 * The format follows bastnic's lab:race (the gist behind symfony/symfony#67391).
 *
 * Every computing process builds a fresh pool per get(), as a PHP-FPM request
 * does; warm=1 keeps one pool per process instead, as a long-running worker
 * does, so TagAwareAdapter may know a tag's version before the miss.
 *
 *   docker compose up -d
 *   php -d apc.enable_cli=1 benchmarks/race.php
 *   php -d apc.enable_cli=1 benchmarks/race.php dsn="redis:?host[127.0.0.1:7110]&host[127.0.0.1:7111]&host[127.0.0.1:7112]&redis_cluster=1&failover=slaves"
 *   php benchmarks/race.php stores=tagaware tree=/path/to/symfony (TagAwareAdapter from a Symfony checkout)
 *   php benchmarks/race.php precision=100
 *
 * Options: dsn=, rounds= (1000), writers= (1,4), modes= (tag,delete),
 *          stores= (tagaware,symfony,versioned-off,versioned; also
 *          versioned-off-exact and versioned-exact, with rulesCacheMs: 0),
 *          compute= (ms, 4), warm=1 (one pool per computing process),
 *          tree= (a symfony/symfony checkout whose Cache component and cache
 *          contracts replace the installed ones), force=1 (flush a non-empty
 *          database)
 *
 * precision=N answers another question: under a stream of unrelated
 * invalidations (rate= per second, 10) during N computations of compute= ms
 * (200), how many writes a single epoch per pool would refuse - every one
 * whose computation saw any rule appended - against how many the versioned
 * pool's per-tag verdict refuses.
 *
 * Every primary is flushed before and after, so point it at a Redis that holds
 * nothing else.
 */

use Artigo\Cache\Adapter\VersionedRedisTagAwareAdapter;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Contracts\Cache\ItemInterface;

$options = [];

foreach (array_slice($argv, 1) as $argument) {
    if (str_contains($argument, '=')) {
        [$name, $value] = explode('=', $argument, 2);
        $options[$name] = $value;
    }
}

require __DIR__.'/../vendor/autoload.php';

// a Symfony checkout's Cache component in place of the installed one, ahead
// of Composer's loader, which would answer first
if (isset($options['tree'])) {
    $tree = rtrim((string) $options['tree'], '/\\');

    spl_autoload_register(static function (string $class) use ($tree): void {
        foreach (['Symfony\\Component\\Cache\\' => '/src/Symfony/Component/Cache/', 'Symfony\\Contracts\\Cache\\' => '/src/Symfony/Contracts/Cache/'] as $prefix => $dir) {
            if (str_starts_with($class, $prefix) && is_file($file = $tree.$dir.strtr(substr($class, strlen($prefix)), '\\', '/').'.php')) {
                require $file;

                return;
            }
        }
    }, true, true);
}

const ITEM = 'race-item';
const TAG = 'race-tag';
const SOURCE = 'race:source';
const STOP = 'race:stop';
const READY = 'race:ready';
const STARTED = 'race:started';
const DONE = 'race:done';
const RULES = 'race:rules';

$dsn = (string) ($options['dsn'] ?? getenv('REDIS_DSN') ?: 'redis://127.0.0.1:6379');

if (isset($options['role'])) {
    match ((string) $options['role']) {
        'writer' => writer($dsn, (string) $options['store'], (string) $options['namespace'], (int) $options['compute'], (bool) ($options['warm'] ?? false), (string) $options['writer']),
        'invalidator' => invalidator($dsn, (string) $options['namespace'], (int) $options['rate']),
        default => throw new RuntimeException('Unknown role.'),
    };

    exit(0);
}

$control = control($dsn);
$primaries = primaries($control);
$force = (bool) ($options['force'] ?? false);

foreach ($primaries as $address => $node) {
    $size = $node->dbSize();

    if ($size > 0 && !$force) {
        fwrite(\STDERR, sprintf('! %s holds %d keys. Point dsn= somewhere empty, or pass force=1.'.\PHP_EOL, $address, $size));

        exit(1);
    }
}

$server = reset($primaries)->info('server');

echo sprintf('%s | %s %s | %d primar%s%s%s',
    $control instanceof RedisCluster ? 'cluster'.(str_contains($dsn, 'failover=') ? ', reads from replicas' : '') : 'standalone',
    str_contains((string) ($server['redis_version'] ?? ''), 'valkey') ? 'Valkey' : 'Redis',
    $server['redis_version'] ?? '?',
    count($primaries),
    1 === count($primaries) ? 'y' : 'ies',
    isset($tree) ? ' | '.(new ReflectionClass(TagAwareAdapter::class))->getFileName() : '',
    \PHP_EOL,
);

if (isset($options['precision'])) {
    flushAll($primaries);
    precision($dsn, $control, max(1, (int) $options['precision']), (int) ($options['compute'] ?? 200), max(1, (int) ($options['rate'] ?? 10)));
    flushAll($primaries);

    exit(0);
}

$rounds = max(1, (int) ($options['rounds'] ?? 1000));
$compute = max(1, (int) ($options['compute'] ?? 4));
$writerCounts = array_map(intval(...), explode(',', (string) ($options['writers'] ?? '1,4')));
$modes = array_values(array_filter(array_map(trim(...), explode(',', (string) ($options['modes'] ?? 'tag,delete')))));
$stores = array_values(array_filter(array_map(trim(...), explode(',', (string) ($options['stores'] ?? 'tagaware,symfony,versioned-off,versioned')))));
$results = [];

$warm = (bool) ($options['warm'] ?? false);

echo sprintf('%d rounds | computations of %d ms | %s%s', $rounds, $compute, $warm ? 'one pool per computing process' : 'a fresh pool per get()', \PHP_EOL);

foreach ($stores as $store) {
    foreach ($writerCounts as $writers) {
        foreach ($modes as $mode) {
            flushAll($primaries);
            $namespace = sprintf('race-%s-%s-%d', $store, $mode, $writers);
            $stale = rounds($dsn, $control, $store, $namespace, $mode, $writers, $rounds, $compute, $warm);
            $results[$store][$writers][$mode] = $stale;

            echo sprintf('  %-14s %d writer%s  %-6s  %4d of %d stale%s', $store, $writers, 1 === $writers ? ' ' : 's', $mode, $stale, $rounds, \PHP_EOL);
        }
    }
}

flushAll($primaries);

// a store without the guard serves a stale value after a delete whenever a
// computation straddled it; none at all means the rounds raced nothing
foreach (['tagaware', 'symfony', 'versioned-off', 'versioned-off-exact'] as $unguarded) {
    foreach ($results[$unguarded] ?? [] as $writers => $byMode) {
        if (0 === ($byMode['delete'] ?? null)) {
            fwrite(\STDERR, sprintf('! %s with %d writer(s) kept no stale value after a delete: no computation straddled one, so the table would say nothing. Raise compute=.'.\PHP_EOL, $unguarded, $writers));

            exit(1);
        }
    }
}

$labels = ['tag' => 'after a tag invalidation', 'delete' => 'after a delete'];

echo \PHP_EOL.'| stack | concurrent writers | '.implode(' | ', array_map(static fn (string $mode): string => sprintf('stale %s, out of %d', $labels[$mode] ?? $mode, $rounds), $modes)).' |'.\PHP_EOL;
echo '|---|---:|'.str_repeat('---:|', count($modes)).\PHP_EOL;

foreach ($results as $store => $byWriters) {
    foreach ($byWriters as $writers => $byMode) {
        echo sprintf('| %s | %d | %s |'.\PHP_EOL, label($store, isset($tree)), $writers, implode(' | ', array_map(static fn (string $mode): string => (string) ($byMode[$mode] ?? '-'), $modes)));
    }
}

/**
 * The rounds of one stack, mode and writer count: how often the old value was
 * still served once every computation that read it had written.
 */
function rounds(string $dsn, Redis|RedisCluster $control, string $store, string $namespace, string $mode, int $writers, int $rounds, int $compute, bool $warm): int
{
    $connection = connect($dsn);
    $invalidate = static function (TagAwareAdapterInterface $pool) use ($mode): void {
        'tag' === $mode ? $pool->invalidateTags([TAG]) : $pool->deleteItem(ITEM);
    };

    $control->del(STOP, READY, STARTED, DONE);
    $control->set(SOURCE, 'r0-old');
    $processes = [];

    for ($i = 0; $i < $writers; ++$i) {
        $processes[] = spawn(['role=writer', 'dsn='.$dsn, 'store='.$store, 'namespace='.$namespace, 'compute='.$compute, 'warm='.($warm ? '1' : '0'), 'writer='.$i]);
    }

    // every writer computing before the first round
    $deadline = microtime(true) + 30;

    while ((int) $control->get(READY) < $writers) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('The writers did not start: '.implode(' ', array_map(stop(...), $processes)));
        }

        usleep(10_000);
    }

    $owner = pool($store, $connection, $namespace);
    $stale = 0;

    for ($round = 1; $round <= $rounds; ++$round) {
        $old = sprintf('r%d-old', $round);

        // the item a miss, the source the old value: the writers compute it
        $control->set(SOURCE, $old);
        $invalidate($owner);

        // a computation is under way somewhere in it
        spin(random_int(0, $compute * 1000) / 1000);

        // every computation that can have read the old value has started
        // by now, and is waited for: counted, not timed, so that a coarse
        // timer cannot make the round read before the stale write lands
        $control->set(SOURCE, sprintf('r%d-new', $round));
        $started = counters($control, STARTED);
        $invalidate($owner);
        $deadline = microtime(true) + 30;

        while (!finished($started, counters($control, DONE))) {
            if (microtime(true) > $deadline) {
                $control->set(STOP, '1');

                throw new RuntimeException('A computation never finished: '.implode(' ', array_map(stop(...), $processes)));
            }

            spin(0.1);
        }

        $item = pool($store, $connection, $namespace)->getItem(ITEM);

        if ($item->isHit() && $old === $item->get()) {
            ++$stale;
        }
    }

    $control->set(STOP, '1');

    foreach ($processes as $process) {
        $error = stop($process);

        if ('' !== $error) {
            fwrite(\STDERR, $error.\PHP_EOL);
        }
    }

    return $stale;
}

/**
 * A computing process: get() in a loop, a fresh pool each time unless warm,
 * until told to stop. Each computation is counted when it starts, before it
 * reads the source, and when the get() that ran it has written.
 */
function writer(string $dsn, string $store, string $namespace, int $compute, bool $warm, string $id): void
{
    $connection = connect($dsn);
    $control = control($dsn);
    $kept = $warm ? pool($store, $connection, $namespace) : null;
    $control->incr(READY);

    while (!$control->exists(STOP)) {
        $computed = 0;

        try {
            ($kept ?? pool($store, $connection, $namespace))->get(ITEM, static function (ItemInterface $item) use ($control, $compute, $id, &$computed): string {
                ++$computed;
                $control->hIncrBy(STARTED, $id, 1);
                $value = (string) $control->get(SOURCE);
                spin($compute);
                $item->tag(TAG);
                $item->expiresAfter(3600);

                return $value;
            });
        } finally {
            // however the get() ended, and however often it computed
            if (0 < $computed) {
                $control->hIncrBy(DONE, $id, $computed);
            }
        }

        spin(0.2);
    }
}

/**
 * Waits $ms milliseconds on the monotonic clock rather than in usleep(),
 * which a coarse system timer stretches to 15 ms on Windows: the straddle
 * the rounds race for is a few milliseconds wide.
 */
function spin(float $ms): void
{
    $until = hrtime(true) + (int) ($ms * 1_000_000);

    while (hrtime(true) < $until) {
    }
}

/**
 * @return array<string, int> per computing process
 */
function counters(Redis|RedisCluster $control, string $key): array
{
    $counters = $control->hGetAll($key);

    return is_array($counters) ? array_map(intval(...), $counters) : [];
}

/**
 * Whether every process has finished the computations it had started: one
 * at a time each, so its count catching up means the one in flight is done.
 *
 * @param array<string, int> $started
 * @param array<string, int> $done
 */
function finished(array $started, array $done): bool
{
    foreach ($started as $id => $count) {
        if (($done[$id] ?? 0) < $count) {
            return false;
        }
    }

    return true;
}

/**
 * Under a stream of unrelated invalidations, how many writes would a single
 * epoch per pool refuse, and how many does the per-tag verdict refuse?
 */
function precision(string $dsn, Redis|RedisCluster $control, int $computations, int $compute, int $rate): void
{
    $namespace = 'precision';
    $control->del(STOP, RULES);
    $invalidator = spawn(['role=invalidator', 'dsn='.$dsn, 'namespace='.$namespace, 'rate='.$rate]);

    while (!$control->exists(RULES)) {
        usleep(10_000);
    }

    $connection = connect($dsn);
    $crossed = 0;
    $refused = 0;

    for ($i = 0; $i < $computations; ++$i) {
        $before = (int) $control->get(RULES);
        $pool = new VersionedRedisTagAwareAdapter($connection, $namespace);

        $pool->get('value-'.$i, static function (ItemInterface $item) use ($compute, $i): string {
            usleep($compute * 1000);
            $item->tag('own-'.$i);

            return 'computed';
        });

        // a pool epoch moves with any rule: such a write would be dropped
        $crossed += $before === (int) $control->get(RULES) ? 0 : 1;
        $refused += (new VersionedRedisTagAwareAdapter($connection, $namespace))->getItem('value-'.$i)->isHit() ? 0 : 1;
    }

    $control->set(STOP, '1');
    $error = stop($invalidator);

    if ('' !== $error) {
        fwrite(\STDERR, $error.\PHP_EOL);
    }

    echo sprintf('%d computations of %d ms under %d unrelated invalidations a second (%d in all)%s', $computations, $compute, $rate, (int) $control->get(RULES), \PHP_EOL);
    echo \PHP_EOL.'| | writes refused |'.\PHP_EOL.'|---|---:|'.\PHP_EOL;
    echo sprintf('| one epoch per pool (every write a rule was appended during) | %d of %d |%s', $crossed, $computations, \PHP_EOL);
    echo sprintf('| **versioned**, per tag | %d of %d |%s', $refused, $computations, \PHP_EOL);
}

/**
 * Appends a rule for a tag nothing carries, $rate times a second, and counts
 * them, until told to stop.
 */
function invalidator(string $dsn, string $namespace, int $rate): void
{
    $pool = new VersionedRedisTagAwareAdapter(connect($dsn), $namespace);
    $control = control($dsn);
    $control->set(RULES, '0');

    for ($i = 0; !$control->exists(STOP); ++$i) {
        $pool->invalidateTags(['unrelated-'.$i]);
        $control->incr(RULES);
        usleep(intdiv(1_000_000, $rate));
    }
}

function pool(string $store, Redis|RedisCluster $connection, string $namespace): TagAwareAdapterInterface
{
    return match ($store) {
        'tagaware' => new TagAwareAdapter(new RedisAdapter($connection, $namespace)),
        'symfony' => new RedisTagAwareAdapter($connection, $namespace),
        'versioned-off' => new VersionedRedisTagAwareAdapter($connection, $namespace, guardWindowSeconds: 0),
        'versioned' => new VersionedRedisTagAwareAdapter($connection, $namespace),
        'versioned-off-exact' => new VersionedRedisTagAwareAdapter($connection, $namespace, rulesCacheMs: 0, guardWindowSeconds: 0),
        'versioned-exact' => new VersionedRedisTagAwareAdapter($connection, $namespace, rulesCacheMs: 0),
        default => throw new RuntimeException(sprintf('Unknown store "%s"; pick from tagaware, symfony, versioned-off, versioned, versioned-off-exact, versioned-exact.', $store)),
    };
}

function label(string $store, bool $tree): string
{
    return match ($store) {
        'tagaware' => $tree ? '`TagAwareAdapter`, the checkout' : '`TagAwareAdapter`',
        'symfony' => '`RedisTagAwareAdapter`',
        'versioned-off' => '**versioned**, `guardWindowSeconds: 0`',
        'versioned' => '**versioned**, the guard on',
        'versioned-off-exact' => '**versioned**, `guardWindowSeconds: 0`, `rulesCacheMs: 0`',
        'versioned-exact' => '**versioned**, the guard on, `rulesCacheMs: 0`',
        default => $store,
    };
}

function connect(string $dsn): Redis|RedisCluster
{
    $connection = RedisAdapter::createConnection($dsn, ['lazy' => false]);

    if (!$connection instanceof Redis && !$connection instanceof RedisCluster) {
        throw new RuntimeException('This benchmark needs an ext-redis connection or cluster.');
    }

    return $connection;
}

/**
 * The connection the processes coordinate over: the primaries only, so that
 * no replica's lag is part of the answer.
 */
function control(string $dsn): Redis|RedisCluster
{
    return connect((string) preg_replace('/&failover=[a-z_]+/', '', $dsn));
}

/**
 * @param list<string> $arguments
 *
 * @return array{resource, array<int, resource>}
 */
function spawn(array $arguments): array
{
    $command = [\PHP_BINARY];

    // the parent's APCu and memory settings, which a CLI child does not inherit
    foreach (['apc.enable_cli', 'apc.shm_size', 'memory_limit'] as $setting) {
        if (false !== $value = ini_get($setting)) {
            $command[] = '-d';
            $command[] = $setting.'='.$value;
        }
    }

    $command[] = __FILE__;

    foreach ($arguments as $argument) {
        $command[] = $argument;
    }

    if (isset($GLOBALS['tree'])) {
        $command[] = 'tree='.$GLOBALS['tree'];
    }

    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('Could not start a process.');
    }

    return [$process, $pipes];
}

/**
 * Waits for a process that was told to stop, and returns what it printed.
 *
 * @param array{resource, array<int, resource>} $process
 */
function stop(array $process): string
{
    [$handle, $pipes] = $process;
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($handle);

    return trim((string) $output);
}

/**
 * @return array<string, Redis>
 */
function primaries(Redis|RedisCluster $connection): array
{
    $addresses = [];

    if ($connection instanceof RedisCluster) {
        foreach ($connection->_masters() as $master) {
            $addresses[] = is_array($master) ? $master[0].':'.$master[1] : (string) $master;
        }
    } else {
        $addresses[] = $connection->getHost().':'.$connection->getPort();
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

    // APCu outlives a flush: a shared rule set would name a stream that is gone
    if (function_exists('apcu_clear_cache')) {
        apcu_clear_cache();
    }
}
