<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * How many requests reach the origin when a hot key goes cold?
 *
 * Spawns N real processes, releases them on a shared wall-clock barrier
 * against one cold key, and counts how many of them run the expensive
 * callback. One is the answer a cache is supposed to give. N is what no
 * protection looks like.
 *
 * The interesting column is what happens to Symfony's LockRegistry as the
 * fleet grows: it flock()s *local* files, so its scope is one machine. A host
 * is, to flock(), nothing more than its own set of lock files - which is what
 * lets this model a 12-server fleet on one laptop.
 *
 *   php benchmarks/stampede.php
 *   php benchmarks/stampede.php workers=12 resolverMs=300 hosts=12
 *   php benchmarks/stampede.php modes=memolock,symfony-multihost
 *
 * Options: dsn=, workers=, resolverMs=, hosts=, lead=, modes=, keys=
 *
 * keys=collide gives every worker a *different* cold key, chosen so they all
 * land on the same LockRegistry slot. LockRegistry spreads the whole keyspace
 * over 25 lock files, so unrelated items share a lock and wait for one
 * another; this is what that costs.
 */

use Symfony\Component\Cache\Adapter\RedisAdapter;

require __DIR__.'/../vendor/autoload.php';

$options = [];

foreach (array_slice($argv, 1) as $argument) {
    if (str_contains($argument, '=')) {
        [$name, $value] = explode('=', $argument, 2);
        $options[$name] = $value;
    }
}

$dsn = (string) ($options['dsn'] ?? getenv('REDIS_DSN') ?: 'redis://127.0.0.1:6379');
$workers = (int) ($options['workers'] ?? 12);
$resolverMs = (int) ($options['resolverMs'] ?? 300);
$hosts = (int) ($options['hosts'] ?? 3);
$lead = (float) ($options['lead'] ?? 1.5);
$modes = explode(',', (string) ($options['modes'] ?? 'nolock,memolock,symfony-onehost,symfony-multihost'));
$colliding = 'collide' === ($options['keys'] ?? '');

if ('\\' === \DIRECTORY_SEPARATOR) {
    fwrite(\STDERR, <<<'TEXT'
        ! Windows: Symfony disables LockRegistry here outright, so its rows will
        ! read 0 protection for a reason that has nothing to do with fleet size.
        ! Run this under Linux (docker compose up -d) for a fair comparison.

        TEXT);
}

$redis = RedisAdapter::createConnection($dsn);
$run = bin2hex(random_bytes(4));
$lockFiles = lockFiles($hosts);
$results = [];

echo sprintf('%s | %d workers | origin takes %d ms | %d simulated hosts%s%s',
    $dsn, $workers, $resolverMs, $hosts, \PHP_EOL, \PHP_EOL);

foreach ($modes as $mode) {
    $mode = trim($mode);

    if ('' === $mode) {
        continue;
    }

    $key = sprintf('cold-%s-%s', $mode, $run);
    $keys = $colliding ? collidingKeys($workers, $mode.$run) : [];
    $counterKey = sprintf('stampede:origin:%s:%s', $mode, $run);
    $redis->del($counterKey);

    $startAt = microtime(true) + $lead;
    $processes = [];

    for ($index = 0; $index < $workers; ++$index) {
        // onehost puts every worker on the same lock files; multihost deals
        // them out, which is exactly what separate machines look like
        $host = 'symfony-multihost' === $mode ? $index % max(1, $hosts) : 0;

        $processes[$index] = spawn([
            'dsn' => $dsn,
            'mode' => $mode,
            'key' => $keys[$index] ?? $key,
            'counterKey' => $counterKey,
            'startAt' => $startAt,
            'resolverMs' => $resolverMs,
            'index' => $index,
            'host' => $host,
            'lockFiles' => $lockFiles[$host],
        ]);
    }

    $reports = [];

    foreach ($processes as $index => $process) {
        $reports[$index] = collect($process, $index);
    }

    $origins = (int) $redis->get($counterKey);
    $redis->del($counterKey);
    (new RedisAdapter($redis, 'stampede-'.$mode))->clear();
    $redis->del([('' === $mode ? '' : 'stampede-'.$mode.':').'@rules']);

    $late = count(array_filter($reports, static fn (array $report): bool => (bool) ($report['late'] ?? false)));
    $errors = array_filter(array_column($reports, 'error'));
    $slowest = max(array_map(static fn (array $report): float => (float) ($report['seconds'] ?? 0), $reports));

    $results[$mode] = [
        'origins' => $origins,
        'slowest' => $slowest,
        'overhead' => $slowest - $resolverMs / 1000,
        'late' => $late,
        'errors' => $errors,
    ];

    echo sprintf('  %-20s %2d/%d origin calls, slowest %.3f s%s%s',
        $mode,
        $origins,
        $workers,
        $slowest,
        $late > 0 ? sprintf(' (%d missed the barrier)', $late) : '',
        \PHP_EOL,
    );

    $spread = array_map(static fn (array $r): string => sprintf('%s%.3f', ($r['origin'] ?? false) ? '*' : '', (float) ($r['seconds'] ?? 0)), $reports);
    echo sprintf('    workers: %s%s', implode(' ', $spread), \PHP_EOL);

    foreach ($errors as $error) {
        echo sprintf('    ! %s%s', $error, \PHP_EOL);
    }
}

echo \PHP_EOL;
echo '| Setup | Origin calls | Slowest worker | Overhead over the origin call |'.\PHP_EOL;
echo '|---|---|---|---|'.\PHP_EOL;

foreach ($results as $mode => $result) {
    echo sprintf('| `%s` | **%d / %d** | %.3f s | %d ms |%s',
        $mode,
        $result['origins'],
        $workers,
        $result['slowest'],
        (int) round($result['overhead'] * 1000),
        \PHP_EOL,
    );
}

echo \PHP_EOL;

/**
 * Distinct keys that all hash to the same LockRegistry slot, so the lock they
 * share is the only thing they have in common.
 *
 * @return list<string>
 */
function collidingKeys(int $count, string $salt): array
{
    $slots = [];

    for ($i = 0; $i < 100000; ++$i) {
        $candidate = sprintf('collide-%s-%d', $salt, $i);
        $slot = abs(crc32($candidate)) % 25;
        $slots[$slot][] = $candidate;

        if (count($slots[$slot]) >= $count) {
            return $slots[$slot];
        }
    }

    throw new RuntimeException('Could not find enough keys sharing a slot.');
}

/**
 * LockRegistry needs files that already exist, and uses their count as its
 * concurrency limit. One directory per simulated host.
 *
 * @return array<int, list<string>>
 */
function lockFiles(int $hosts): array
{
    $files = [];

    for ($host = 0; $host < max(1, $hosts); ++$host) {
        $directory = sprintf('%s/artigo-stampede/host-%d', sys_get_temp_dir(), $host);

        if (!is_dir($directory) && !mkdir($directory, 0o777, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create "%s".', $directory));
        }

        for ($slot = 0; $slot < 25; ++$slot) {
            $file = sprintf('%s/lock-%02d', $directory, $slot);

            if (!is_file($file)) {
                touch($file);
            }

            $files[$host][] = $file;
        }
    }

    return $files;
}

/**
 * @param array<string, mixed> $payload
 *
 * @return array{resource, array<int, resource>}
 */
function spawn(array $payload): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $command = [\PHP_BINARY, __DIR__.'/src/stampede-worker.php', json_encode($payload, \JSON_THROW_ON_ERROR)];
    $process = proc_open($command, $descriptors, $pipes);

    if (!is_resource($process)) {
        throw new RuntimeException('Could not start a worker.');
    }

    return [$process, $pipes];
}

/**
 * @param array{resource, array<int, resource>} $process
 *
 * @return array<string, mixed>
 */
function collect(array $process, int $index): array
{
    [$handle, $pipes] = $process;

    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);

    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($handle);

    $report = json_decode(trim((string) $out), true);

    if (!is_array($report)) {
        return ['index' => $index, 'seconds' => 0.0, 'error' => trim($err ?: 'the worker said nothing')];
    }

    return $report;
}
