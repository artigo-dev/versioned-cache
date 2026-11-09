<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * What does a request pay for its reads, in time, once a round trip costs
 * something?
 *
 * A request is a fresh pool, as PHP-FPM gives every request, reading N keys
 * that are all there: through a get() loop, the way most code reads, or
 * through one getItems(). Every item carries a tag of its own (an entity tag)
 * and a tag it shares with nine others (a list tag); shared=N gives it N such
 * list tags. Behind the items lie behind=N invalidations of tags no item
 * carries: the rule set a versioned pool copies into every request.
 *
 * The round trip is whatever the link costs. Through toxiproxy, latency=N
 * delays every reply by N ms while the requests run. The proxy costs a round
 * trip something of its own, so run once straight at Redis and once through
 * the proxy with latency=0 to see both. The "flaky" profile of
 * compose.tests.yaml runs a Redis behind a toxiproxy; the proxy is created on
 * first use.
 *
 *   docker compose up -d
 *   php -d apc.enable_cli=1 benchmarks/request.php
 *   php -d apc.enable_cli=1 benchmarks/request.php pattern=batch behind=10000
 *   docker compose -f compose.tests.yaml --profile flaky up -d --wait
 *   php -d apc.enable_cli=1 benchmarks/request.php dsn=redis://127.0.0.1:6397 latency=0
 *   php -d apc.enable_cli=1 benchmarks/request.php dsn=redis://127.0.0.1:6397 latency=1
 *
 * Options: dsn=, reads= (10), pattern= (loop or batch), behind= (2000),
 *          requests= (300), shared= (1), stores= (redis,symfony,tagaware,versioned),
 *          latency= (ms added by toxiproxy; no proxy is touched without it),
 *          proxy= (toxiproxy's API, http://127.0.0.1:8474),
 *          upstream= (the Redis behind it, redis-behind-proxy:6379)
 *
 * Every run writes under namespaces of its own and flushes nothing. The time
 * is the mean per request, so that it reads like the totals of bench.php; the
 * median and the 95th percentile are printed beside it. Round trips are the
 * socket reads Redis counted, per request, so nothing else may talk to that
 * Redis while it runs.
 */

use Artigo\Cache\Adapter\VersionedRedisTagAwareAdapter;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\Adapter\RedisTagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapterInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

require __DIR__.'/../vendor/autoload.php';

$options = [];

foreach (array_slice($argv, 1) as $argument) {
    if (str_contains($argument, '=')) {
        [$name, $value] = explode('=', $argument, 2);
        $options[$name] = $value;
    }
}

$dsn = (string) ($options['dsn'] ?? getenv('REDIS_DSN') ?: 'redis://127.0.0.1:6379');
$reads = max(1, (int) ($options['reads'] ?? 10));
$pattern = 'batch' === ($options['pattern'] ?? 'loop') ? 'batch' : 'loop';
$behind = max(0, (int) ($options['behind'] ?? 2000));
$requests = max(1, (int) ($options['requests'] ?? 300));
$shared = max(0, (int) ($options['shared'] ?? 1));
$latency = isset($options['latency']) ? max(0, (int) $options['latency']) : null;
$proxy = rtrim((string) ($options['proxy'] ?? 'http://127.0.0.1:8474'), '/');
$upstream = (string) ($options['upstream'] ?? 'redis-behind-proxy:6379');
$stores = array_values(array_filter(array_map(trim(...), explode(',', (string) ($options['stores'] ?? 'redis,symfony,tagaware,versioned')))));

// the requests read windows of N keys in turn, fifty of them, so that a
// request rarely meets the keys the one before it read
$windows = 50;
$value = str_repeat('v', 100);
$run = bin2hex(random_bytes(3));

// the proxy has to be there before anything connects through it
if (null !== $latency) {
    proxy($proxy, (int) (parse_url($dsn, \PHP_URL_PORT) ?: 6379), $upstream);
}

$connection = RedisAdapter::createConnection($dsn, ['lazy' => false]);

if (!$connection instanceof Redis) {
    fwrite(\STDERR, '! request.php needs ext-redis and a single node.'.\PHP_EOL);

    exit(1);
}

$counter = new Redis();
$counter->connect($connection->getHost(), $connection->getPort());

echo sprintf('%d reads per request through %s | %s rules behind | %d list tag%s per item | %s'.\PHP_EOL,
    $reads,
    'loop' === $pattern ? 'a get() loop' : 'getItems()',
    number_format($behind),
    $shared,
    1 === $shared ? '' : 's',
    null === $latency ? 'straight at Redis' : sprintf('through toxiproxy, %d ms added per reply', $latency),
);

$rows = [];

foreach ($stores as $store) {
    $make = static fn (): AdapterInterface => match ($store) {
        'versioned' => new VersionedRedisTagAwareAdapter($connection, 'rv'.$run),
        'symfony' => new RedisTagAwareAdapter($connection, 'rs'.$run),
        'tagaware' => new TagAwareAdapter(new RedisAdapter($connection, 'rt'.$run)),
        'redis' => new RedisAdapter($connection, 'rr'.$run),
        default => throw new RuntimeException(sprintf('Unknown store "%s"; pick from redis, symfony, tagaware, versioned.', $store)),
    };

    // every item, written through the pool's own API
    $pool = $make();
    $items = $reads * $windows;

    for ($i = 0; $i < $items; $i += 100) {
        foreach ($pool->getItems(keys($i, min(100, $items - $i))) as $key => $item) {
            $item->set($value);
            $item->expiresAfter(3600);

            if ($pool instanceof TagAwareAdapterInterface) {
                $item->tag(tags((string) $key, $shared));
            }

            $pool->saveDeferred($item);
        }

        $pool->commit();
    }

    // the rules behind: invalidations of tags no item carries, one per call
    if ($pool instanceof TagAwareAdapterInterface) {
        for ($i = 0; $i < $behind; ++$i) {
            $pool->invalidateTags(['nobody-'.$i]);
        }
    }

    // every window read once, so that a versioned pool finds the rule set a
    // busy server's workers share
    for ($r = 0; $r < $windows; ++$r) {
        request($make(), keys($r * $reads, $reads), $pattern, $value, $shared);
    }

    if (null !== $latency) {
        toxic($proxy, $latency);
    }

    $before = readsProcessed($counter);
    $times = [];
    $misses = 0;

    try {
        for ($r = 0; $r < $requests; ++$r) {
            $keys = keys(($r % $windows) * $reads, $reads);
            $began = hrtime(true);
            $misses += request($make(), $keys, $pattern, $value, $shared);
            $times[] = (hrtime(true) - $began) / 1e6;
        }
    } finally {
        if (null !== $latency) {
            toxic($proxy, 0);
        }
    }

    $trips = (readsProcessed($counter) - $before) / $requests;
    sort($times);

    $rows[$store] = [
        'trips' => $trips,
        'mean' => array_sum($times) / count($times),
        'median' => $times[intdiv(count($times), 2)],
        'p95' => $times[(int) (count($times) * 0.95)],
        'misses' => $misses,
    ];

    echo sprintf('  %-10s %5.1f round trips, mean %.2f ms, median %.2f ms, p95 %.2f ms, %d misses'.\PHP_EOL,
        $store,
        $trips,
        $rows[$store]['mean'],
        $rows[$store]['median'],
        $rows[$store]['p95'],
        $misses,
    );
}

echo \PHP_EOL.'| store | round trips per request | mean | median | p95 |'.\PHP_EOL;
echo '|---|---:|---:|---:|---:|'.\PHP_EOL;

foreach ($rows as $store => $row) {
    echo sprintf('| %s | %g | %.2f ms | %.2f ms | %.2f ms |'.\PHP_EOL,
        match ($store) {
            'versioned' => '`VersionedRedisTagAwareAdapter`',
            'symfony' => '`RedisTagAwareAdapter`',
            'tagaware' => '`TagAwareAdapter`',
            default => '`RedisAdapter`',
        },
        round($row['trips'], 1),
        $row['mean'],
        $row['median'],
        $row['p95'],
    );
}

/**
 * @return list<string>
 */
function keys(int $from, int $count): array
{
    $keys = [];

    for ($i = $from; $i < $from + $count; ++$i) {
        $keys[] = 'item-'.$i;
    }

    return $keys;
}

/**
 * An entity tag of the item's own, and $shared list tags, each shared by ten
 * items.
 *
 * @return list<string>
 */
function tags(string $key, int $shared): array
{
    $index = (int) substr($key, 5);
    $tags = ['e-'.$index];

    for ($k = 0; $k < $shared; ++$k) {
        $tags[] = 'g'.$k.'-'.intdiv($index, 10);
    }

    return $tags;
}

/**
 * One request: the misses it met, which should be none.
 *
 * @param list<string> $keys
 */
function request(AdapterInterface $pool, array $keys, string $pattern, string $value, int $shared): int
{
    $misses = 0;

    if ('loop' === $pattern) {
        foreach ($keys as $key) {
            assert($pool instanceof CacheInterface);
            $pool->get($key, static function (ItemInterface $item) use (&$misses, $key, $pool, $value, $shared): string {
                ++$misses;

                if ($pool instanceof TagAwareAdapterInterface) {
                    $item->tag(tags($key, $shared));
                }

                return $value;
            });
        }

        return $misses;
    }

    foreach ($pool->getItems($keys) as $key => $item) {
        if ($item->isHit()) {
            continue;
        }

        ++$misses;
        $item->set($value);

        if ($pool instanceof TagAwareAdapterInterface) {
            $item->tag(tags((string) $key, $shared));
        }

        $pool->saveDeferred($item);
    }

    if ($misses > 0) {
        $pool->commit();
    }

    return $misses;
}

/**
 * The socket reads Redis counted, less the one this INFO costs.
 */
function readsProcessed(Redis $counter): int
{
    $stats = $counter->info('stats');

    return (int) ($stats['total_reads_processed'] ?? 0) - 1;
}

/**
 * Makes sure toxiproxy has a proxy named "redis" listening on the port the
 * DSN names, in front of $upstream.
 */
function proxy(string $api, int $port, string $upstream): void
{
    $existing = json_decode((string) @file_get_contents($api.'/proxies/redis', false, stream_context_create(['http' => ['ignore_errors' => true]])), true);

    if (is_array($existing) && isset($existing['name'])) {
        return;
    }

    $created = call($api.'/proxies', 'POST', ['name' => 'redis', 'listen' => '0.0.0.0:'.$port, 'upstream' => $upstream, 'enabled' => true]);

    if (!isset($created['name'])) {
        throw new RuntimeException(sprintf('toxiproxy at %s did not create the proxy.', $api));
    }
}

/**
 * Delays every reply by $milliseconds; 0 takes the delay away.
 */
function toxic(string $api, int $milliseconds): void
{
    @file_get_contents($api.'/proxies/redis/toxics/latency', false, stream_context_create(['http' => ['method' => 'DELETE', 'ignore_errors' => true]]));

    if (0 === $milliseconds) {
        return;
    }

    $added = call($api.'/proxies/redis/toxics', 'POST', ['name' => 'latency', 'type' => 'latency', 'stream' => 'downstream', 'attributes' => ['latency' => $milliseconds, 'jitter' => 0]]);

    if (!isset($added['name'])) {
        throw new RuntimeException(sprintf('toxiproxy at %s refused the latency toxic.', $api));
    }
}

/**
 * @param array<string, mixed> $body
 *
 * @return array<mixed>
 */
function call(string $url, string $method, array $body): array
{
    $answer = @file_get_contents($url, false, stream_context_create(['http' => [
        'method' => $method,
        'header' => "Content-Type: application/json\r\n",
        'content' => json_encode($body, \JSON_THROW_ON_ERROR),
        'ignore_errors' => true,
    ]]));
    $decoded = json_decode((string) $answer, true);

    return is_array($decoded) ? $decoded : [];
}
