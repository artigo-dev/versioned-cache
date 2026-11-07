<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * A worker that takes the reload flag and dies of a fatal error before it lets
 * go. SharedRulesTest looks at the flag afterwards.
 *
 *   php dying-reloader.php <rules key> <rulesCache directory>
 */

use Artigo\Cache\Adapter\SharedRules;
use Artigo\Cache\Tests\Connection;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

require dirname(__DIR__, 3).'/vendor/autoload.php';

$flag = SharedRules::of(new FilesystemAdapter('rules', 0, (string) ($argv[2] ?? '')), (string) ($argv[1] ?? ''), Connection::openSingleNodeAs(Redis::class));

if (null === $flag || !$flag->lead()) {
    exit(1);
}

echo "took the flag\n";

// a fatal error, which no finally sees: a gigabyte against a limit of 8 MB
ini_set('memory_limit', '8M');
$grow = [];

for ($i = 0; $i < 1_024; ++$i) {
    $grow[] = str_repeat('x', 1 << 20);
}
