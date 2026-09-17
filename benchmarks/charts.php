<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Draws the picture the numbers are for, into docs/.
 *
 *   php benchmarks/charts.php
 *
 * The data below is measured output, not illustration - each block says which
 * script produced it and under what conditions. Re-run those, edit these, and
 * regenerate; nothing here is drawn from imagination.
 */

namespace Artigo\Cache\Benchmarks;

/*
 * php benchmarks/bench.php items=20000
 * 20 000 items, 18 tags each, Redis 8, PHP 8.5. Commands executed by Redis to
 * invalidate one tag, by how many items that tag matched.
 */
const INVALIDATION = [
    'labels' => ['1 000', '2 000', '20 000'],
    'series' => [
        ['name' => 'versioned', 'colour' => '#3ddc97', 'values' => [4, 4, 4]],
        ['name' => 'RedisTagAwareAdapter', 'colour' => '#ff6b6b', 'values' => [1006, 2006, 20012]],
        ['name' => 'TagAwareAdapter', 'colour' => '#ffd166', 'values' => [1, 1, 1]],
    ],
];

/*
 * php benchmarks/bench.php items=20000 · php benchmarks/bench.php items=20000 unique=1
 * The same 20 000 items read once each as hits, on Redis 8.8, PHP 8.5, over
 * loopback. Socket reads Redis did - round trips - by whether every item
 * carries a tag of its own (the entity tag applications really use) or only
 * tags it shares with others.
 */
const READS = [
    'labels' => ['shared tags only', 'one tag of its own'],
    'series' => [
        ['name' => 'versioned', 'colour' => '#3ddc97', 'values' => [20010, 20010]],
        ['name' => 'RedisTagAwareAdapter', 'colour' => '#ff6b6b', 'values' => [20000, 20000]],
        ['name' => 'TagAwareAdapter', 'colour' => '#ffd166', 'values' => [27710, 40000]],
    ],
];

$out = \dirname(__DIR__).'/docs';

if (!is_dir($out) && !mkdir($out, 0o777, true) && !is_dir($out)) {
    fwrite(\STDERR, 'Could not create docs/.'.\PHP_EOL);

    exit(1);
}

file_put_contents($out.'/invalidation.svg', chart(
    'Commands to invalidate one tag',
    'items the tag matched',
    INVALIDATION,
    logarithmic: true,
    format: static fn (float $v): string => number_format($v),
));

echo 'wrote docs/invalidation.svg'.\PHP_EOL;

file_put_contents($out.'/reads.svg', chart(
    'Round trips for 20 000 read hits',
    'what an item is tagged with',
    READS,
    logarithmic: false,
    format: static fn (float $v): string => number_format($v),
));

echo 'wrote docs/reads.svg'.\PHP_EOL;

/**
 * @param array{labels: list<string>, series: list<array{name: string, colour: string, values: list<int>}>} $data
 */
function chart(string $title, string $axis, array $data, bool $logarithmic, \Closure $format): string
{
    $width = 760;
    $height = 380;
    $left = 78;
    $right = 250;
    $top = 62;
    $bottom = 64;

    $plotWidth = $width - $left - $right;
    $plotHeight = $height - $top - $bottom;

    $values = [];

    foreach ($data['series'] as $series) {
        foreach ($series['values'] as $value) {
            $values[] = (float) $value;
        }
    }

    $max = max($values);
    // the logarithmic floor sits below 1, so a series worth one command has a
    // line of its own above the axis rather than lying on it
    $min = $logarithmic ? 0.5 : 0.0;

    $scale = static function (float $value) use ($logarithmic, $min, $max, $top, $plotHeight): float {
        $position = $logarithmic
            ? (log10(max($value, $min)) - log10($min)) / (log10($max) - log10($min))
            : $value / $max;

        return $top + $plotHeight - $position * $plotHeight;
    };

    $count = \count($data['labels']);
    // an inset, so the first point does not sit on the axis and its label does
    // not land on top of a gridline number
    $inset = 30.0;
    $span = $plotWidth - $inset;
    $x = static fn (int $index): float => $left + $inset + ($count > 1 ? $index * ($span - $inset) / ($count - 1) : $span / 2);

    $svg = [];
    $svg[] = \sprintf('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 %d %d" width="%d" height="%d" font-family="Rajdhani, system-ui, -apple-system, Segoe UI, sans-serif">', $width, $height, $width, $height);
    $svg[] = '<style>@font-face { font-family: "Rajdhani"; font-weight: 600; src: url("src/fonts/rajdhani-600.woff2") format("woff2"); }'
        .'@font-face { font-family: "Rajdhani"; font-weight: 700; src: url("src/fonts/rajdhani-700.woff2") format("woff2"); }</style>';
    $svg[] = \sprintf('<rect width="%d" height="%d" fill="#0a0a12"/>', $width, $height);
    $svg[] = \sprintf('<text x="%d" y="32" font-size="17" font-weight="600" fill="#e8e8f0">%s</text>', $left - 40, e($title));

    // gridlines
    $ticks = $logarithmic ? [1, 10, 100, 1000, 10000, 100000] : range(0, (int) $max, max(1, (int) ceil($max / 4)));

    foreach ($ticks as $tick) {
        if ($tick > $max * 1.2 || ($logarithmic && $tick < 1)) {
            continue;
        }

        $y = $scale((float) $tick);
        $svg[] = \sprintf('<line x1="%d" y1="%.1f" x2="%.1f" y2="%.1f" stroke="#1c1c2a" stroke-width="1"/>', $left, $y, $left + $plotWidth, $y);
        $svg[] = \sprintf('<text x="%d" y="%.1f" font-size="11" fill="#8b8ba7" text-anchor="end">%s</text>', $left - 10, $y + 4, e($format((float) $tick)));
    }

    // where two series share a point their labels would sit on each other:
    // rank them at every x, put the highest one's label above its point and
    // stack the others underneath theirs, each pushed down until it clears
    // the label before it. A label that would land on the axis goes to the
    // lower right of its point instead, where nothing else is
    $floor = $top + $plotHeight;
    $labels = [];

    foreach ($data['labels'] as $index => $ignored) {
        $column = [];

        foreach ($data['series'] as $position => $series) {
            $column[$position] = $series['values'][$index];
        }

        arsort($column);
        $baseline = null;

        foreach (array_keys($column) as $rank => $position) {
            $y = $scale((float) $column[$position]);

            if (0 === $rank) {
                $labels[$index][$position] = [$x($index), $y - 12.0, 'middle'];

                continue;
            }

            $baseline = null === $baseline ? $y + 19.0 : max($y + 19.0, $baseline + 13.0);

            $labels[$index][$position] = $baseline > $floor - 4.0
                ? [$x($index) + 9.0, min($y + 14.0, $floor - 3.0), 'start']
                : [$x($index), $baseline, 'middle'];
        }
    }

    // the same for the names at the line ends: sorted by where the lines end,
    // each name pushed down until it clears the one above
    $last = \count($data['labels']) - 1;
    $ends = [];

    foreach ($data['series'] as $position => $series) {
        $ends[$position] = $series['values'][$last];
    }

    arsort($ends);
    $names = [];
    $baseline = null;

    foreach (array_keys($ends) as $position) {
        $y = $scale((float) $ends[$position]) + 4.0;
        $baseline = null === $baseline ? $y : max($y, $baseline + 15.0);
        // a name pushed off its own line also moves right, clear of the value
        // labels stacked under the last points
        $names[$position] = [$baseline > $y ? 30.0 : 14.0, $baseline];
    }

    // the series, last first, so the first one named is drawn on top where
    // two of them run together
    foreach (array_reverse($data['series'], true) as $position => $series) {
        $points = [];

        foreach ($series['values'] as $index => $value) {
            $points[] = \sprintf('%.1f,%.1f', $x($index), $scale((float) $value));
        }

        $svg[] = \sprintf('<polyline points="%s" fill="none" stroke="%s" stroke-width="2.5" stroke-linejoin="round"/>', implode(' ', $points), $series['colour']);

        foreach ($series['values'] as $index => $value) {
            $svg[] = \sprintf('<circle cx="%.1f" cy="%.1f" r="4.5" fill="%s"/>', $x($index), $scale((float) $value), $series['colour']);
            [$labelX, $labelY, $anchor] = $labels[$index][$position];
            $svg[] = \sprintf('<text x="%.1f" y="%.1f" font-size="11.5" font-weight="600" fill="%s" text-anchor="%s">%s</text>',
                $labelX, $labelY, $series['colour'], $anchor, e($format((float) $value)));
        }

        // the line names itself at its own end, so no legend is needed
        [$shift, $nameY] = $names[$position];
        $svg[] = \sprintf('<text x="%.1f" y="%.1f" font-size="13" font-weight="600" fill="%s">%s</text>',
            $x($last) + $shift, $nameY, $series['colour'], e($series['name']));
    }

    // the x axis
    $svg[] = \sprintf('<line x1="%d" y1="%.1f" x2="%.1f" y2="%.1f" stroke="#26263a" stroke-width="1"/>', $left, $top + $plotHeight, $left + $plotWidth, $top + $plotHeight);

    foreach ($data['labels'] as $index => $label) {
        $svg[] = \sprintf('<text x="%.1f" y="%.1f" font-size="12" fill="#b8b8c8" text-anchor="middle">%s</text>', $x($index), $top + $plotHeight + 22, e($label));
    }

    $svg[] = \sprintf('<text x="%.1f" y="%.1f" font-size="11.5" fill="#8b8ba7" text-anchor="middle">%s</text>', $left + $plotWidth / 2, $height - 16, e($axis));
    $svg[] = '</svg>';

    return implode("\n", $svg)."\n";
}

function e(string $text): string
{
    return htmlspecialchars($text, \ENT_QUOTES | \ENT_XML1);
}
