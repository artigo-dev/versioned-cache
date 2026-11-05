<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/*
 * Deflate against zstd for cache values: what each costs and what it saves.
 *
 * No adapter, no Redis: the codecs alone, on generated values of three
 * shapes, built deterministically from a seed:
 *
 *   models  serialized product models of ~2.6 KB (natural text, URLs,
 *           identifiers), the shape of bastnic's fixtures
 *   small   values of ~100, ~300 and ~800 bytes, where a frame's own
 *           overhead decides whether compressing pays
 *   html    one rendered product-list fragment of ~50 KB
 *
 * It prints, as markdown tables:
 *
 *   1. ratio, compress and decompress time per value for deflate (gzdeflate,
 *      levels -1, 1, 6) and zstd (levels 1, 3, 4)
 *   2. the threshold: from which size on a frame is smaller than the raw
 *      value, per shape
 *   3. the read-path probe, per raw value: a failed @gzinflate() (how
 *      DeflateMarshaller reads a value it did not compress), inflate_add() on
 *      the first 128 bytes (the write-path probe of symfony/symfony#67332),
 *      and a 4-byte compare against zstd's magic number
 *   4. bytes on the wire for 100 models: raw, deflate -1, zstd 3
 *
 * Every time is the median of `runs` runs; a run repeats each operation until
 * it has taken at least `minMs` milliseconds.
 *
 *   php benchmarks/marshaller.php
 *   php benchmarks/marshaller.php runs=11 minMs=50 seed=7
 *
 * Options: runs= (7), minMs= (30), seed= (2026), models= (100)
 */

$options = [];

foreach (array_slice($argv, 1) as $argument) {
    if (str_contains($argument, '=')) {
        [$name, $value] = explode('=', $argument, 2);
        $options[$name] = $value;
    }
}

$runs = max(1, (int) ($options['runs'] ?? 7));
$minMs = max(1, (int) ($options['minMs'] ?? 30));
$seed = (int) ($options['seed'] ?? 2026);
$modelCount = max(1, (int) ($options['models'] ?? 100));

if (!function_exists('zstd_compress')) {
    fwrite(\STDERR, 'ext-zstd is not loaded: zstd_compress() does not exist.'.\PHP_EOL);
    exit(1);
}

const ZSTD_MAGIC = "\x28\xB5\x2F\xFD";

$codecs = [
    'deflate -1' => [static fn (string $value): string => gzdeflate($value, -1), static fn (string $frame): string => gzinflate($frame)],
    'deflate 1' => [static fn (string $value): string => gzdeflate($value, 1), static fn (string $frame): string => gzinflate($frame)],
    'deflate 6' => [static fn (string $value): string => gzdeflate($value, 6), static fn (string $frame): string => gzinflate($frame)],
    'zstd 1' => [static fn (string $value): string => zstd_compress($value, 1), static fn (string $frame): string => zstd_uncompress($frame)],
    'zstd 3' => [static fn (string $value): string => zstd_compress($value, 3), static fn (string $frame): string => zstd_uncompress($frame)],
    'zstd 4' => [static fn (string $value): string => zstd_compress($value, 4), static fn (string $frame): string => zstd_uncompress($frame)],
];

mt_srand($seed);
$models = [];
for ($i = 0; $i < $modelCount; ++$i) {
    $models[] = serialize(productModel($i));
}

$classes = [
    'models ~2.6 KB' => $models,
    'small ~100 B' => smallValues(100, 50),
    'small ~300 B' => smallValues(300, 50),
    'small ~800 B' => smallValues(800, 50),
    'html ~50 KB' => [htmlFragment(50 * 1024)],
];

echo sprintf('PHP %s, ext-zstd %s (libzstd %s), zlib %s | %d runs, >= %d ms each, seed %d%s%s',
    \PHP_VERSION, phpversion('zstd'), defined('ZSTD_VERSION_TEXT') ? \ZSTD_VERSION_TEXT : '?', \ZLIB_VERSION,
    $runs, $minMs, $seed, \PHP_EOL, \PHP_EOL);

echo '## Ratio and time per value'.\PHP_EOL.\PHP_EOL;
echo 'Ratio = compressed bytes / raw bytes over every value of the class; times in µs per value (median of the runs).'.\PHP_EOL.\PHP_EOL;
echo '| Values | Raw bytes (mean) | Codec | Ratio | Compress µs | Decompress µs |'.\PHP_EOL;
echo '|---|---:|---|---:|---:|---:|'.\PHP_EOL;

foreach ($classes as $class => $values) {
    $rawBytes = array_sum(array_map('strlen', $values));
    $timed = atLeast(50, $values);

    foreach ($codecs as $codec => [$compress, $decompress]) {
        $frames = array_map($compress, $values);
        $timedFrames = array_map($compress, $timed);

        foreach ($values as $index => $value) {
            if ($decompress($frames[$index]) !== $value) {
                throw new LogicException(sprintf('%s does not round-trip a value of %s.', $codec, $class));
            }
        }

        $compressNs = medianNs($runs, $minMs, static function () use ($timed, $compress): int {
            foreach ($timed as $value) {
                $compress($value);
            }

            return count($timed);
        });
        $decompressNs = medianNs($runs, $minMs, static function () use ($timedFrames, $decompress): int {
            foreach ($timedFrames as $frame) {
                $decompress($frame);
            }

            return count($timedFrames);
        });

        echo sprintf('| %s | %s | %s | %.3f | %.2f | %.2f |%s',
            $class,
            number_format($rawBytes / count($values)),
            $codec,
            array_sum(array_map('strlen', $frames)) / $rawBytes,
            $compressNs / 1000,
            $decompressNs / 1000,
            \PHP_EOL);
    }
}

echo \PHP_EOL.'## The threshold'.\PHP_EOL.\PHP_EOL;
echo 'The smallest size from which on the median frame (over 25 values of that size and shape) is smaller than the raw value, for every size up to 2048 bytes.'.\PHP_EOL.\PHP_EOL;
echo '| Shape | zstd 1 | zstd 3 | zstd 4 | deflate -1 |'.\PHP_EOL;
echo '|---|---:|---:|---:|---:|'.\PHP_EOL;

$shapes = [
    'model (serialized product)' => static fn (int $variant): string => serialize(productModel(10_000 + $variant)),
    'small (serialized record)' => static fn (int $variant): string => smallValues(900, 1, 20_000 + $variant)[0],
    'html (rendered fragment)' => static fn (int $variant): string => htmlFragment(4096, 30_000 + $variant),
];

foreach ($shapes as $shape => $generate) {
    $sources = [];
    for ($variant = 0; $variant < 25; ++$variant) {
        $sources[] = $generate($variant);
    }

    $row = [];
    foreach (['zstd 1', 'zstd 3', 'zstd 4', 'deflate -1'] as $codec) {
        $row[] = threshold($sources, $codecs[$codec][0], 2048);
    }

    echo sprintf('| %s | %s |%s', $shape, implode(' | ', array_map(
        static fn (?int $bytes): string => null === $bytes ? '> 2048' : number_format($bytes).' B',
        $row,
    )), \PHP_EOL);
}

echo \PHP_EOL.'## The read-path probe'.\PHP_EOL.\PHP_EOL;
echo 'Per raw (uncompressed) value: how a reader or writer tells it from a compressed one. ns per value; x100 = µs for a request reading 100 values.'.\PHP_EOL.\PHP_EOL;
echo '| Raw value | failed @gzinflate() ns | x100 µs | inflate_add() on 128 B ns | x100 µs | 4-byte magic compare ns | x100 µs |'.\PHP_EOL;
echo '|---|---:|---:|---:|---:|---:|---:|'.\PHP_EOL;

$probes = [
    'failed @gzinflate()' => static function (array $values): int {
        $accepted = 0;
        foreach ($values as $value) {
            $accepted += false !== @gzinflate($value) ? 1 : 0;
        }

        return $accepted;
    },
    'inflate_add() on 128 B' => static function (array $values): int {
        $accepted = 0;
        foreach ($values as $value) {
            $accepted += false !== @inflate_add(inflate_init(\ZLIB_ENCODING_RAW), substr($value, 0, 128), \ZLIB_SYNC_FLUSH) ? 1 : 0;
        }

        return $accepted;
    },
    '4-byte magic compare' => static function (array $values): int {
        $accepted = 0;
        foreach ($values as $value) {
            $accepted += str_starts_with($value, ZSTD_MAGIC) ? 1 : 0;
        }

        return $accepted;
    },
];
$accepted = [];

foreach ($classes as $class => $values) {
    $cells = [];
    $timed = atLeast(50, $values);

    foreach ($probes as $probe => $test) {
        $accepted[$probe] = ($accepted[$probe] ?? 0) + $test($values);

        $ns = medianNs($runs, $minMs, static function () use ($timed, $test): int {
            $test($timed);

            return count($timed);
        });
        $cells[] = sprintf('%s | %s', number_format($ns, 0), number_format($ns * 100 / 1000, 2));
    }

    echo sprintf('| %s | %s |%s', $class, implode(' | ', $cells), \PHP_EOL);
}

echo \PHP_EOL.sprintf('Raw values a probe took for compressed: %s.',
    implode(', ', array_map(static fn (string $probe, int $count): string => $probe.' '.$count, array_keys($accepted), $accepted))).\PHP_EOL;

echo \PHP_EOL.'## Bytes on the wire, 100 models'.\PHP_EOL.\PHP_EOL;
echo '| Stored as | Bytes | KB | Of raw |'.\PHP_EOL;
echo '|---|---:|---:|---:|'.\PHP_EOL;

$hundred = array_slice(array_merge($models, $models), 0, 100);
$rawWire = array_sum(array_map('strlen', $hundred));
foreach (['raw' => null, 'deflate -1' => $codecs['deflate -1'][0], 'zstd 3' => $codecs['zstd 3'][0]] as $storedAs => $compress) {
    $bytes = null === $compress ? $rawWire : array_sum(array_map(static fn (string $value): int => strlen($compress($value)), $hundred));
    echo sprintf('| %s | %s | %.1f | %.0f %% |%s', $storedAs, number_format($bytes), $bytes / 1024, 100 * $bytes / $rawWire, \PHP_EOL);
}

/**
 * The median, over $runs runs, of the time one operation of $pass takes in ns:
 * a run repeats $pass (which answers how many operations it made) until it
 * has taken $minMs.
 */
function medianNs(int $runs, int $minMs, Closure $pass): float
{
    $pass();
    $samples = [];

    for ($run = 0; $run < $runs; ++$run) {
        $operations = 0;
        $started = hrtime(true);
        do {
            $operations += $pass();
            $elapsed = hrtime(true) - $started;
        } while ($elapsed < $minMs * 1_000_000);

        $samples[] = $elapsed / $operations;
    }

    sort($samples);

    return $samples[intdiv(count($samples), 2)];
}

/**
 * $values repeated until there are at least $count of them (the strings are
 * shared, not copied).
 *
 * @param list<string> $values
 *
 * @return list<string>
 */
function atLeast(int $count, array $values): array
{
    $list = $values;
    while (count($list) < $count) {
        $list = array_merge($list, $values);
    }

    return $list;
}

/**
 * The smallest size n from which on a prefix of every length m >= n (up to
 * $max) gives a median frame shorter than m; null when there is none.
 *
 * @param list<string> $sources
 */
function threshold(array $sources, Closure $compress, int $max): ?int
{
    $found = null;

    for ($size = $max; $size >= 1; --$size) {
        $lengths = [];
        foreach ($sources as $source) {
            $lengths[] = strlen($compress(substr(str_pad($source, $max, $source), 0, $size)));
        }
        sort($lengths);

        if ($lengths[intdiv(count($lengths), 2)] >= $size) {
            return $found;
        }

        $found = $size;
    }

    return $found;
}

/**
 * A product as a catalogue would cache it: identifiers, a slug, URLs, prices,
 * attributes and a description in natural language.
 *
 * @return array<string, mixed>
 */
function productModel(int $index): array
{
    $name = ucfirst(words(mt_rand(3, 5)));
    $slug = strtolower(str_replace(' ', '-', $name));
    $id = 100_000 + $index;

    return [
        'id' => $id,
        'uuid' => sprintf('%08x-%04x-4%03x-%04x-%06x%06x', mt_rand(), mt_rand(0, 0xFFFF), mt_rand(0, 0xFFF), mt_rand(0x8000, 0xBFFF), mt_rand(0, 0xFFFFFF), mt_rand(0, 0xFFFFFF)),
        'sku' => sprintf('%s-%05d-%s', strtoupper(substr($slug, 0, 3)), $id % 100_000, strtoupper(dechex(mt_rand(0x100, 0xFFF)))),
        'name' => $name,
        'slug' => $slug,
        'url' => sprintf('https://shop.example.com/%s/%s-%d', word(), $slug, $id),
        'images' => [
            sprintf('https://cdn.example.com/media/catalog/product/%s/%s/%s-%d-front.jpg', dechex(mt_rand(0, 15)), dechex(mt_rand(0, 15)), $slug, $id),
            sprintf('https://cdn.example.com/media/catalog/product/%s/%s/%s-%d-side.jpg', dechex(mt_rand(0, 15)), dechex(mt_rand(0, 15)), $slug, $id),
        ],
        'category' => ['id' => mt_rand(10, 999), 'path' => ucfirst(word()).' / '.ucfirst(word()).' / '.ucfirst(word())],
        'brand' => ['id' => mt_rand(1, 400), 'name' => ucfirst(word()).' '.ucfirst(word())],
        'price' => ['amount' => mt_rand(499, 49_999), 'currency' => 'EUR', 'taxRate' => 20],
        'stock' => ['quantity' => mt_rand(0, 500), 'warehouse' => 'WH-'.mt_rand(1, 9)],
        'attributes' => [
            'colour' => word(),
            'material' => word(),
            'weightGrams' => mt_rand(50, 9000),
            'dimensions' => sprintf('%d x %d x %d mm', mt_rand(10, 900), mt_rand(10, 900), mt_rand(10, 900)),
        ],
        'tags' => array_map(static fn (): string => word(), range(1, mt_rand(3, 6))),
        'summary' => sentences(2),
        'description' => sentences(mt_rand(18, 22)),
        'rating' => ['average' => mt_rand(10, 50) / 10, 'count' => mt_rand(0, 2000)],
        'createdAt' => sprintf('2026-%02d-%02dT%02d:%02d:%02d+00:00', mt_rand(1, 10), mt_rand(1, 28), mt_rand(0, 23), mt_rand(0, 59), mt_rand(0, 59)),
    ];
}

/**
 * $count serialized records of about $bytes each: an id, a name, a URL and a
 * note sized to reach the target.
 *
 * @return list<string>
 */
function smallValues(int $bytes, int $count, ?int $seed = null): array
{
    if (null !== $seed) {
        mt_srand($seed);
    }

    $values = [];
    for ($i = 0; $i < $count; ++$i) {
        $record = ['id' => mt_rand(1, 999_999), 'name' => ucfirst(words(2)), 'url' => '/'.word().'/'.mt_rand(1, 99_999), 'note' => ''];
        $note = '';
        while (strlen(serialize(['note' => $note] + $record)) < $bytes) {
            $note .= ('' === $note ? '' : ' ').word();
        }
        $record['note'] = $note;
        $values[] = serialize($record);
    }

    return $values;
}

/**
 * A rendered product list of about $bytes: the markup a page cache or an
 * ESI fragment would store.
 */
function htmlFragment(int $bytes, ?int $seed = null): string
{
    if (null !== $seed) {
        mt_srand($seed);
    }

    $html = '<section class="product-list" data-page="1">'."\n".'<ul class="products">'."\n";
    $index = 0;
    while (strlen($html) < $bytes) {
        $name = ucfirst(words(mt_rand(2, 4)));
        $slug = strtolower(str_replace(' ', '-', $name));
        $id = 200_000 + $index++;
        $html .= sprintf(
            '<li class="product-card" data-id="%d"><a href="https://shop.example.com/%s/%s-%d" class="product-link">'
            .'<img src="https://cdn.example.com/media/%s-%d-thumb.webp" alt="%s" width="320" height="320" loading="lazy"></a>'
            .'<h3 class="product-name">%s</h3><p class="product-teaser">%s</p>'
            .'<span class="price" data-currency="EUR">%s €</span><button type="button" class="add-to-cart" data-sku="SKU-%d">Add to cart</button></li>'."\n",
            $id, word(), $slug, $id, $slug, $id, $name, $name, sentences(1), number_format(mt_rand(499, 49_999) / 100, 2, ',', '.'), $id,
        );
    }

    return $html.'</ul>'."\n".'</section>'."\n";
}

function sentences(int $count): string
{
    $text = [];
    for ($i = 0; $i < $count; ++$i) {
        $text[] = ucfirst(words(mt_rand(8, 16))).'.';
    }

    return implode(' ', $text);
}

function words(int $count): string
{
    return implode(' ', array_map(static fn (): string => word(), range(1, $count)));
}

function word(): string
{
    static $vocabulary = null;
    $vocabulary ??= explode(' ', 'the a and of to in with for on this that is are be by from as at it its your our'
        .' made built designed comfortable durable light soft warm water resistant breathable everyday outdoor travel'
        .' jacket shoe bag bottle chair table lamp desk shelf cushion blanket backpack wallet watch scarf glove boot'
        .' cotton wool leather steel oak bamboo linen recycled organic premium classic modern compact portable'
        .' black white grey navy olive sand blue green red brown natural'
        .' fits easily keeps holds carries protects supports lasts cleans folds stores'
        .' day night season winter summer trip city home office kitchen garden'
        .' quality detail finish strap pocket zip handle seam sole lining frame surface edge'
        .' small large extra simple practical elegant robust sturdy smooth quiet bright'
        .' every all more less long short wide narrow high low new first best');

    // natural text draws its common words far more often than its rare ones
    return $vocabulary[(int) (count($vocabulary) * (mt_rand() / mt_getrandmax()) ** 2.2) % count($vocabulary)];
}
