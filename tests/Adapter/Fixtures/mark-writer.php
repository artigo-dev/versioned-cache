<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * A worker of a server writing the mark of its invalidation into the pool the
 * server shares, as a process of its own. SharedRulesTest starts it while
 * another worker is writing its own mark.
 *
 *   php mark-writer.php <rulesCache directory> <rules key> <at ms> <rule id>
 */

use Artigo\Cache\Adapter\SharedRules;
use Artigo\Cache\Tests\Adapter\SpyRedis;
use Artigo\Cache\Tests\Connection;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$shared = SharedRules::of(new FilesystemAdapter('rules', 0, (string) ($argv[1] ?? '')), (string) ($argv[2] ?? ''), Connection::openSingleNodeAs(SpyRedis::class));

if (null === $shared) {
    exit(1);
}

$shared->written((float) ($argv[3] ?? 0), (string) ($argv[4] ?? ''));

echo "written\n";
