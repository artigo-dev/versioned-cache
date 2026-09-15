<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * One request in a stampede. Started by stampede.php, it waits on a shared
 * wall-clock barrier so that every worker hits the same cold key at the same
 * moment, then asks the pool for it exactly once and reports what happened as
 * a single JSON line on stdout.
 *
 * Not meant to be run by hand.
 */

use Artigo\Cache\Adapter\VersionedRedisTagAwareAdapter;
use Artigo\Cache\FleetLock;
use Artigo\Cache\MemoLock;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Component\Cache\LockRegistry;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\RedisStore;
use Symfony\Contracts\Cache\ItemInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$options = json_decode((string) ($argv[1] ?? '{}'), true, 16, \JSON_THROW_ON_ERROR);

$dsn = (string) $options['dsn'];
$mode = (string) $options['mode'];
$key = (string) $options['key'];
$counterKey = (string) $options['counterKey'];
$startAt = (float) $options['startAt'];
$resolverMs = (int) $options['resolverMs'];
$index = (int) $options['index'];
$host = (int) $options['host'];
$lockFiles = $options['lockFiles'];

$redis = RedisAdapter::createConnection($dsn);

// the versioned adapter is a Symfony pool like any other, so the lock hangs
// off it the same way - which is the point of keeping the two apart
$pool = 'versioned-memolock' === $mode
    ? new VersionedRedisTagAwareAdapter($redis, 'stampede-'.$mode)
    : new RedisAdapter($redis, 'stampede-'.$mode);

switch ($mode) {
    case 'nolock':
        // the wrapper Symfony falls back to when locking is off: compute, always
        $pool->setCallbackWrapper(null);
        break;

    case 'memolock':
    case 'versioned-memolock':
        $pool->setCallbackWrapper(MemoLock::fromDsn(
            $dsn,
            lockTtlMs: max(5_000, $resolverMs * 10),
            waitTimeoutMs: max(2_000, $resolverMs * 4),
        ));
        break;

    case 'fleetlock':
        // the shape this would take in symfony/cache: any Lock store, no
        // Redis dependency of its own - and, for every store but a couple,
        // a 100 ms poll rather than a message
        $pool->setCallbackWrapper(new FleetLock(new LockFactory(new RedisStore($redis))));
        break;

    case 'symfony-onehost':
    case 'symfony-multihost':
        // a host is, to flock(), nothing more than its own set of lock files
        LockRegistry::setFiles($lockFiles);

        // Symfony turns locking off under the CLI SAPI; a web request would
        // have it on, so the benchmark puts it back
        $pool->setCallbackWrapper(LockRegistry::compute(...));
        break;

    default:
        fwrite(\STDERR, sprintf('Unknown mode "%s".%s', $mode, \PHP_EOL));

        exit(1);
}

$origin = false;

$resolve = static function (ItemInterface $item) use ($redis, $counterKey, $resolverMs, &$origin): string {
    $origin = true;
    $item->expiresAfter(60);

    // an expensive thing, counted where every process can see it
    $redis->incr($counterKey);
    usleep($resolverMs * 1000);

    return 'resolved';
};

// the barrier: everybody leaves at the same instant
$late = microtime(true) > $startAt;

while (microtime(true) < $startAt) {
    usleep(200);
}

$began = microtime(true);
$error = null;

try {
    $value = $pool->get($key, $resolve);
} catch (Throwable $e) {
    $value = null;
    $error = $e->getMessage();
}

echo json_encode([
    'index' => $index,
    'host' => $host,
    'origin' => $origin,
    'seconds' => microtime(true) - $began,
    'late' => $late,
    'value' => $value,
    'error' => $error,
], \JSON_THROW_ON_ERROR), \PHP_EOL;
