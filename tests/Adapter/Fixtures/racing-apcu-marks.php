<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Two workers of a server writing the marks of their invalidations into APCu
 * at once - B's lands between A's look at the mark and A's write - and then a
 * third worker, whose set was read between the two rules, reading the item
 * the later rule invalidated. Prints what it saw. SharedRulesTest runs it.
 *
 *   php -d apc.enable_cli=1 racing-apcu-marks.php <namespace> <A's id> <A's time> <B's id> <B's time> <the set's read time>
 */

use Artigo\Cache\Adapter\InvalidationRules;
use Artigo\Cache\Adapter\SharedRules;
use Artigo\Cache\Adapter\VersionedRedisTagAwareAdapter;
use Artigo\Cache\Tests\Adapter\SpyRedis;
use Artigo\Cache\Tests\Connection;

// a hook in front of every APCu write SharedRules makes
eval(<<<'PHP'
    namespace Artigo\Cache\Adapter;

    function apcu_cas(string $key, int $old, int $new): bool
    {
        \beforeApcuWrite($key);

        return \apcu_cas($key, $old, $new);
    }

    function apcu_add(mixed ...$arguments): mixed
    {
        \beforeApcuWrite((string) $arguments[0]);

        return \apcu_add(...$arguments);
    }

    function apcu_store(mixed ...$arguments): mixed
    {
        \beforeApcuWrite((string) $arguments[0]);

        return \apcu_store(...$arguments);
    }
    PHP);

/** @var Closure(string): void|null $hook */
$hook = null;

function beforeApcuWrite(string $key): void
{
    global $hook;

    if ($hook instanceof Closure && str_contains($key, '_mark')) {
        $run = $hook;
        $hook = null;
        $run();
    }
}

require dirname(__DIR__, 3).'/vendor/autoload.php';

$namespace = (string) ($argv[1] ?? '');
$idA = (string) ($argv[2] ?? '');
$atA = (float) ($argv[3] ?? 0);
$idB = (string) ($argv[4] ?? '');
$atB = (float) ($argv[5] ?? 0);
$readAt = (float) ($argv[6] ?? 0);
$rulesKey = $namespace.':@rules';
$redis = Connection::openSingleNodeAs(SpyRedis::class);

// the third worker's set: read between the two rules, so holding A's only
$rules = new InvalidationRules($namespace.':', 2_592_000_000);
$rules->absorb((array) $redis->xRange($rulesKey, '-', $idA));
$a = SharedRules::of(null, $rulesKey, $redis);
$b = SharedRules::of(null, $rulesKey, $redis);

if (null === $a || null === $b) {
    exit(1);
}

$a->forget();
$a->store($rules, $readAt, true);

// B's mark lands between A's look and A's write
$hook = static function () use ($b, $atB, $idB): void {
    $b->written($atB, $idB);
};
$a->written($atA, $idA);

$mark = $a->writtenMark();
$redis->forgetRanges();
$third = new VersionedRedisTagAwareAdapter($redis, $namespace, rulesCacheMs: 60_000);
$hit = $third->getItem('itemB')->isHit();

echo json_encode(['mark' => $mark, 'hit' => $hit, 'attempts' => count($redis->starts)], \JSON_THROW_ON_ERROR), "\n";
$a->forget();
