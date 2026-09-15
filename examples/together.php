<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * The two packages in one afternoon.
 *
 * An editor publishes a change. Every article card carrying the "articles" tag
 * has to go - twenty thousand of them - and then every one of them is cold at
 * the same moment, on every server you run, while the traffic keeps arriving.
 *
 * The adapter makes the first half cheap: one appended rule, four commands,
 * whatever the tag matched. The lock makes the second half survivable: the
 * first request through computes, the rest wait for it rather than piling onto
 * the database beside it.
 *
 *   docker compose up -d
 *   php examples/together.php
 *
 * Runs against REDIS_DSN, or redis://127.0.0.1 by default.
 */

use Artigo\Cache\Adapter\VersionedRedisTagAwareAdapter;
use Artigo\Cache\MemoLock;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use Symfony\Contracts\Cache\ItemInterface;

require __DIR__.'/../vendor/autoload.php';

$dsn = getenv('REDIS_DSN') ?: 'redis://127.0.0.1:6379';

// One connection for the cache, one DSN for the lock. MemoLock opens a second
// connection of its own when it needs to wait on Pub/Sub, because a connection
// in subscribe mode cannot serve anything else.
$pool = new VersionedRedisTagAwareAdapter(RedisAdapter::createConnection($dsn), 'example');
$pool->setCallbackWrapper(MemoLock::fromDsn($dsn));

$pool->clear();

$origin = 0;

/**
 * Whatever is expensive: a query, an API call, a render. Counted so the
 * example can show how often it was actually reached.
 */
$renderCard = static function (ItemInterface $item, int $id) use (&$origin): array {
    ++$origin;
    $item->expiresAfter(3600);
    $item->tag(['articles', 'article-'.$id, 'front-page']);

    return ['id' => $id, 'title' => 'Article '.$id, 'rendered_at' => microtime(true)];
};

// --- warm the cache the way an application would ------------------------

for ($id = 1; $id <= 5; ++$id) {
    $pool->get('card-'.$id, static fn (ItemInterface $item): array => $renderCard($item, $id));
}

printf("warmed 5 cards, origin reached %d times\n", $origin);

// a second pass costs nothing: every one of them is a hit
$before = $origin;

for ($id = 1; $id <= 5; ++$id) {
    $pool->get('card-'.$id, static fn (ItemInterface $item): array => $renderCard($item, $id));
}

printf("read them again, origin reached %d more times\n", $origin - $before);

// --- the editor publishes ------------------------------------------------

$began = microtime(true);
$pool->invalidateTags(['articles']);
printf("invalidated the 'articles' tag in %.2f ms - four commands, and it would\n", (microtime(true) - $began) * 1000);
printf("have been four for twenty thousand cards just as much as for five\n");

// one tag, one card: the rule model treats both the same way
$pool->invalidateTags(['article-3']);

// --- everything is cold again, and the herd arrives ----------------------

$before = $origin;

for ($id = 1; $id <= 5; ++$id) {
    $pool->get('card-'.$id, static fn (ItemInterface $item): array => $renderCard($item, $id));
}

printf("read after invalidation, origin reached %d times (all five were stale)\n", $origin - $before);

// What the lock is for does not show in a single process - one request cannot
// stampede itself. benchmarks/stampede.php in artigo/cache-stampede releases
// twelve real processes at one cold key and counts how many reach the origin:
// one, against twelve with no lock and twelve with Symfony's own on a fleet.

$pool->clear();
echo "done\n";
