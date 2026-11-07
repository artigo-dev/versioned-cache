<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * A worker whose APCu refuses every write, as APCu does while another process
 * expunges it, reading and writing with the rule set kept in APCu, and
 * printing what it did. A full APCu cannot stand in: it is wiped, not refused.
 * RuleSetReloadTest runs it.
 *
 *   php -d apc.enable_cli=1 refusing-apcu-reader.php <namespace>
 */

use Artigo\Cache\Adapter\SharedRules;
use Artigo\Cache\Adapter\VersionedRedisTagAwareAdapter;
use Artigo\Cache\Tests\Adapter\SpyRedis;
use Artigo\Cache\Tests\Connection;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

// the writes SharedRules makes are refused; its reads go to APCu as they are
eval(<<<'PHP'
    namespace Artigo\Cache\Adapter;

    function apcu_add(mixed ...$arguments): bool
    {
        return false;
    }

    function apcu_store(mixed ...$arguments): bool
    {
        return false;
    }

    function apcu_cas(mixed ...$arguments): bool
    {
        return false;
    }
    PHP);

require dirname(__DIR__, 3).'/vendor/autoload.php';

$logger = new class extends AbstractLogger {
    public int $warnings = 0;

    public function log($level, string|Stringable $message, array $context = []): void
    {
        if (LogLevel::WARNING === $level) {
            ++$this->warnings;
        }
    }
};

$redis = Connection::openSingleNodeAs(SpyRedis::class);
$pool = new VersionedRedisTagAwareAdapter($redis, (string) ($argv[1] ?? ''), rulesCacheMs: 50);
$pool->setLogger($logger);

$hits = [$pool->getItem('item')->isHit(), $pool->getItem('item')->isHit()];

// past the window: it loads the rules again
usleep(60_000);
$hits[] = $pool->getItem('item')->isHit();
$saved = $pool->save($pool->getItem('written')->set('v'));

echo json_encode([
    'apcu' => SharedRules::apcuEnabled(),
    'hits' => $hits,
    'attempts' => count($redis->starts),
    'saved' => $saved,
    'warnings' => $logger->warnings,
], \JSON_THROW_ON_ERROR), "\n";
