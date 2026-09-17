<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests\Adapter;

/**
 * A connection that remembers where each XRANGE started and how often the
 * watermark was asked for.
 */
final class SpyRedis extends \Redis
{
    /** @var list<string> */
    public array $starts = [];

    public int $watermarks = 0;

    /**
     * @return \Redis|array<mixed>|bool
     */
    public function xRange(string $key, string $start, string $end, int $count = -1): \Redis|array|bool
    {
        $this->starts[] = $start;

        return parent::xRange($key, $start, $end, $count);
    }

    /**
     * @return \Redis|array<mixed>|bool
     */
    public function xRevRange(string $key, string $end, string $start, int $count = -1): \Redis|array|bool
    {
        ++$this->watermarks;

        return parent::xRevRange($key, $end, $start, $count);
    }
}
