<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * One worker of a server, as a process of its own: at the moment it is given,
 * it reads one item - through get(), with a lock that notes it was taken, or
 * through getItem() - while the rules stream answers late or fails, and prints
 * what it did. RuleSetReloadTest runs several at once; the workers share the
 * rule set, and its flag, through one directory. Given a lock file, the lock
 * waits while another process holds that file.
 *
 *   php rules-reader.php <namespace> <directory> <start at> <rulesCacheMs> <get|read> <fail after ms, or -1> <answer after ms> [<lock file>]
 */

use Artigo\Cache\Adapter\VersionedRedisTagAwareAdapter;
use Artigo\Cache\Tests\Adapter\SpyRedis;
use Artigo\Cache\Tests\Connection;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\ItemInterface;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$namespace = (string) ($argv[1] ?? '');
$directory = (string) ($argv[2] ?? '');
$startAt = (float) ($argv[3] ?? 0);
$rulesCacheMs = (int) ($argv[4] ?? 0);
$through = (string) ($argv[5] ?? 'read');
$failAfterMs = (int) ($argv[6] ?? -1);
$answerAfterMs = (int) ($argv[7] ?? 0);
$lockFile = (string) ($argv[8] ?? '');

$redis = Connection::openSingleNodeAs(SpyRedis::class);
$redis->rangesFail = $failAfterMs >= 0;
$redis->failAfterMs = max(0, $failAfterMs);
$redis->answerAfterMs = $answerAfterMs;
$pool = new VersionedRedisTagAwareAdapter($redis, $namespace, rulesCacheMs: $rulesCacheMs, rulesCache: new FilesystemAdapter('rules', 0, $directory));

$locked = false;
$pool->setCallbackWrapper(static function (callable $callback, ItemInterface $item, bool &$save) use (&$locked, $lockFile): mixed {
    $locked = true;
    $handle = '' !== $lockFile ? fopen($lockFile, 'c') : false;

    if (false === $handle) {
        return $callback($item, $save);
    }

    // queued behind whoever holds the file, as a stampede lock queues a waiter
    flock($handle, \LOCK_EX);

    try {
        return $callback($item, $save);
    } finally {
        flock($handle, \LOCK_UN);
        fclose($handle);
    }
});

// every worker reads at the moment it was given
$wait = $startAt - microtime(true);

if ($wait > 0) {
    usleep((int) ($wait * 1_000_000));
}

$began = microtime(true);

if ('get' === $through) {
    $computed = false;
    $pool->get('item', static function () use (&$computed): string {
        $computed = true;

        return 'computed';
    });
    $hit = !$computed;
} else {
    $hit = $pool->getItem('item')->isHit();
}

echo json_encode(['hit' => $hit, 'attempts' => count($redis->starts), 'ms' => (microtime(true) - $began) * 1000, 'locked' => $locked], \JSON_THROW_ON_ERROR), "\n";
