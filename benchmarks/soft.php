<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * What does a visitor wait for after a tag invalidation, hard and soft?
 *
 * A menu edit invalidates the tag 300 page fragments carry. Visitors arrive
 * at once, each page reading its own share of the fragments, each fragment
 * taking the origin a while to compute. After a hard invalidation every
 * visitor computes its share before it is answered. After a soft one every
 * visitor is served the old fragments at once, and the refreshes run after
 * the response - one per fragment, elected through MemoLock.
 *
 * The pools are artigo/cache-stampede's StaleCache in soft mode over this
 * adapter with a soft grace, the way an application would put them together,
 * so it needs cache-stampede 2.0 installed.
 *
 *   docker compose up -d
 *   php benchmarks/soft.php
 *   php benchmarks/soft.php fragments=300 visitors=12 originMs=20
 *
 * Options: dsn=, fragments= (300), visitors= (12), originMs= (20), modes= (hard,soft)
 */

use Artigo\Cache\Adapter\VersionedRedisTagAwareAdapter;
use Artigo\Cache\MemoLock;
use Artigo\Cache\Stale\RefreshQueue;
use Artigo\Cache\StaleCache;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Contracts\Cache\ItemInterface;

require __DIR__.'/../vendor/autoload.php';

$options = [];

foreach (array_slice($argv, 1) as $argument) {
    if (str_contains($argument, '=')) {
        [$name, $value] = explode('=', $argument, 2);
        $options[$name] = $value;
    }
}

const ORIGIN = 'soft:origin';

$dsn = (string) ($options['dsn'] ?? getenv('REDIS_DSN') ?: 'redis://127.0.0.1:6379');
$fragments = max(1, (int) ($options['fragments'] ?? 300));
$visitors = max(1, (int) ($options['visitors'] ?? 12));
$originMs = max(1, (int) ($options['originMs'] ?? 20));

if (!class_exists(StaleCache::class)) {
    fwrite(\STDERR, 'This benchmark needs artigo/cache-stampede 2.0, which has StaleCache.'.\PHP_EOL);

    exit(1);
}

if (isset($options['visitor'])) {
    visit($dsn, (string) $options['namespace'], (int) $options['visitor'], $visitors, $fragments, $originMs, (float) $options['startAt']);

    exit(0);
}

$redis = connect($dsn);
$modes = array_values(array_filter(array_map(trim(...), explode(',', (string) ($options['modes'] ?? 'hard,soft')))));
$rows = [];

echo sprintf('%s | %d fragments, one tag | %d visitors, %d fragments each | origin takes %d ms a fragment%s', $dsn, $fragments, $visitors, intdiv($fragments, $visitors), $originMs, \PHP_EOL);

foreach ($modes as $mode) {
    $namespace = sprintf('soft-%s-%s', $mode, bin2hex(random_bytes(3)));
    $pool = stale($redis, $namespace, null);

    // the page fragments, all carrying the tag a menu edit invalidates
    for ($i = 0; $i < $fragments; ++$i) {
        $pool->get('fragment-'.$i, static function (ItemInterface $item): string {
            $item->expiresAfter(300);
            $item->tag('menu');

            return 'old';
        });
    }

    $pool->invalidateTags(['menu'], 'hard' === $mode);
    $redis->del(ORIGIN.':during', ORIGIN.':after');

    $startAt = microtime(true) + 1.0;
    $processes = [];

    for ($v = 0; $v < $visitors; ++$v) {
        $processes[] = spawn(['visitor='.$v, 'dsn='.$dsn, 'namespace='.$namespace, 'visitors='.$visitors, 'fragments='.$fragments, 'originMs='.$originMs, 'startAt='.$startAt]);
    }

    $waits = [];
    $waited = 0;

    foreach ($processes as $process) {
        $report = collect($process);
        $waits[] = (float) ($report['seconds'] ?? 0);
        // waited for the origin: computed something before its response
        $waited += 0 < (int) ($report['computed'] ?? 0) ? 1 : 0;
    }

    sort($waits);

    $rows[$mode] = [
        'waited' => $waited,
        'median' => $waits[intdiv(count($waits), 2)] ?? 0.0,
        'max' => end($waits) ?: 0.0,
        'during' => (int) $redis->get(ORIGIN.':during'),
        'after' => (int) $redis->get(ORIGIN.':after'),
    ];

    $pool->clear();

    echo sprintf('  %-5s %d of %d waited, p50 %d ms, max %d ms, origin %d during the responses and %d after them%s',
        $mode, $rows[$mode]['waited'], $visitors, (int) round($rows[$mode]['median'] * 1000), (int) round($rows[$mode]['max'] * 1000), $rows[$mode]['during'], $rows[$mode]['after'], \PHP_EOL);
}

echo \PHP_EOL.'| invalidation | visitors who waited for the origin | wait p50 | wait max | origin calls during the responses | after them |'.\PHP_EOL;
echo '|---|---:|---:|---:|---:|---:|'.\PHP_EOL;

foreach ($rows as $mode => $row) {
    echo sprintf('| %s | %d / %d | %d ms | %d ms | %d | %d |'.\PHP_EOL, $mode, $row['waited'], $visitors, (int) round($row['median'] * 1000), (int) round($row['max'] * 1000), $row['during'], $row['after']);
}

/**
 * One visitor: its share of the fragments, the time it waited for them, and
 * the refreshes after its response.
 */
function visit(string $dsn, string $namespace, int $visitor, int $visitors, int $fragments, int $originMs, float $startAt): void
{
    $redis = connect($dsn);
    $queue = new RefreshQueue();
    $pool = stale($redis, $namespace, $queue);
    $phase = 'during';
    $computed = 0;

    $origin = static function (ItemInterface $item) use ($redis, $originMs, &$phase, &$computed): string {
        $redis->incr(ORIGIN.':'.$phase);
        $computed += 'during' === $phase ? 1 : 0;
        usleep($originMs * 1000);
        $item->expiresAfter(300);
        $item->tag('menu');

        return 'new';
    };

    $share = intdiv($fragments, $visitors);

    while (microtime(true) < $startAt) {
        usleep(200);
    }

    $began = microtime(true);

    for ($i = $visitor * $share; $i < ($visitor + 1) * $share; ++$i) {
        $pool->get('fragment-'.$i, $origin);
    }

    $seconds = microtime(true) - $began;

    // the response is out: the refreshes run now
    $phase = 'after';
    $queue->flush();

    echo json_encode(['seconds' => $seconds, 'computed' => $computed], \JSON_THROW_ON_ERROR), \PHP_EOL;
}

/**
 * The pools an application would build: the adapter with a soft grace and
 * MemoLock as its lock, under StaleCache in soft mode, refreshing after the
 * response through $queue.
 */
function stale(Redis $redis, string $namespace, ?RefreshQueue $queue): StaleCache
{
    $lock = new MemoLock($redis);
    $inner = new VersionedRedisTagAwareAdapter($redis, $namespace, rulesCacheMs: 0, rulesCache: false, softGraceSeconds: 600);
    $inner->setCallbackWrapper($lock);

    $pool = new StaleCache($inner, staleSeconds: 60, election: $lock, soft: true);
    $pool->setDeferrer($queue);

    return $pool;
}

function connect(string $dsn): Redis
{
    $redis = RedisAdapter::createConnection($dsn, ['lazy' => false]);

    if (!$redis instanceof Redis) {
        throw new RuntimeException('This benchmark needs a single ext-redis node.');
    }

    return $redis;
}

/**
 * @param list<string> $arguments
 *
 * @return array{resource, array<int, resource>}
 */
function spawn(array $arguments): array
{
    $process = proc_open([\PHP_BINARY, __FILE__, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('Could not start a visitor.');
    }

    return [$process, $pipes];
}

/**
 * @param array{resource, array<int, resource>} $process
 *
 * @return array<string, mixed>
 */
function collect(array $process): array
{
    [$handle, $pipes] = $process;
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($handle);

    $report = json_decode(trim((string) $out), true);

    if (!is_array($report)) {
        fwrite(\STDERR, trim($err ?: (string) $out).\PHP_EOL);

        return [];
    }

    return $report;
}
