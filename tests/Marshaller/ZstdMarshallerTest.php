<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests\Marshaller;

use Artigo\Cache\Adapter\VersionedRedisTagAwareAdapter;
use Artigo\Cache\Marshaller\ZstdMarshaller;
use Artigo\Cache\Tests\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Exception\InvalidArgumentException;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;
use Symfony\Component\Cache\Marshaller\DeflateMarshaller;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;

/**
 * What the marshaller writes, what it reads back, and what it refuses: a
 * frame only where it pays, one format, and every value written before it
 * still readable.
 */
final class ZstdMarshallerTest extends TestCase
{
    private const MAGIC = "\x28\xB5\x2F\xFD";

    /**
     * A skippable frame: a magic number from 0x184D2A50 to 0x184D2A5F, then
     * the length of the bytes a decoder skips (RFC 8878, 3.1.2).
     */
    private const SKIPPABLE = "\x50\x2A\x4D\x18\x04\x00\x00\x00skip";

    protected function setUp(): void
    {
        if (!\function_exists('zstd_compress')) {
            self::markTestSkipped('ext-zstd is not loaded.');
        }
    }

    public function testAValueBelowTheThresholdIsStoredAsTheInnerWroteIt(): void
    {
        $inner = new DefaultMarshaller();
        $value = str_repeat('a', 200);

        $stored = (new ZstdMarshaller($inner))->marshall(['k' => $value], $failed);

        self::assertSame($inner->marshall(['k' => $value], $ignored), $stored, 'compressible, but shorter than the threshold');
        self::assertSame([], $failed);
    }

    public function testALargeValueIsStoredAsAFrameAndReadBack(): void
    {
        $marshaller = new ZstdMarshaller(new DefaultMarshaller());
        $model = self::model();

        $stored = self::stored($marshaller, $model);

        self::assertStringStartsWith(self::MAGIC, $stored);
        self::assertLessThan(\strlen(serialize($model)), \strlen($stored));
        self::assertSame($model, $marshaller->unmarshall($stored));
    }

    public function testAValueTheFrameWouldNotShrinkStaysRaw(): void
    {
        // random bytes do not compress: the frame is longer than the value
        $noise = "\x00".random_bytes(4_096);

        $stored = (new ZstdMarshaller(new PassThroughMarshaller(), threshold: 0))->marshall(['k' => $noise], $failed);

        self::assertSame($noise, $stored['k']);
    }

    public function testARawValueBeginningWithTheMagicIsAFrameWhateverItsSize(): void
    {
        $marshaller = new ZstdMarshaller(new PassThroughMarshaller());
        $lookalike = self::MAGIC.'tiny';

        $stored = self::stored($marshaller, $lookalike, $failed);

        self::assertNotSame($lookalike, $stored, 'stored raw, it would be read as a frame');
        self::assertSame($lookalike, $marshaller->unmarshall($stored));
        self::assertSame([], $failed);
    }

    public function testAValueWrittenWithoutTheMarshallerReadsAsItIs(): void
    {
        $inner = new DefaultMarshaller();
        $model = self::model();
        $before = self::stored($inner, $model);

        self::assertSame($model, (new ZstdMarshaller($inner))->unmarshall($before));
    }

    public function testADeflatedValueReachesTheInnerMarshallerUnchanged(): void
    {
        $deflated = self::stored(new DeflateMarshaller(new DefaultMarshaller()), self::model());
        $inner = new PassThroughMarshaller();

        (new ZstdMarshaller($inner))->unmarshall($deflated);

        self::assertSame([$deflated], $inner->read, 'no header to recognise: the inner refuses it, a miss');
    }

    public function testACorruptFrameIsRefused(): void
    {
        // an inner marshaller that accepts anything: the refusal is this one's
        $marshaller = new ZstdMarshaller(new PassThroughMarshaller());
        $stored = self::stored($marshaller, serialize(self::model()));

        // the header kept, the blocks after it overwritten
        $damaged = substr($stored, 0, 12).str_repeat("\xFF", \strlen($stored) - 12);

        $this->expectException(\DomainException::class);

        $marshaller->unmarshall($damaged);
    }

    public function testATruncatedFrameIsRefused(): void
    {
        $marshaller = new ZstdMarshaller(new PassThroughMarshaller());
        $stored = self::stored($marshaller, serialize(self::model()));

        $this->expectException(\DomainException::class);

        $marshaller->unmarshall(substr($stored, 0, intdiv(\strlen($stored), 2)));
    }

    public function testATruncatedFrameOfManyBlocksIsRefused(): void
    {
        // past 128 KiB a frame has several blocks, and a cut one inflates to
        // the blocks before the cut
        $marshaller = new ZstdMarshaller(new PassThroughMarshaller());
        $stored = self::stored($marshaller, self::text(400_000));

        $this->expectException(\DomainException::class);

        $marshaller->unmarshall(substr($stored, 0, intdiv(\strlen($stored), 2)));
    }

    public function testAFrameStatingMoreThanTheCapIsRefusedUnread(): void
    {
        // a whole frame of a 2 KiB value, which would inflate without fault
        $frame = zstd_compress(self::text(2_048));
        self::assertIsString($frame);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('states no content size or one out of bounds');

        (new ZstdMarshaller(new PassThroughMarshaller(), maxLength: 1_024))->unmarshall($frame);
    }

    public function testAFrameStatingNoSizeIsRefused(): void
    {
        if (!\function_exists('zstd_compress_init')) {
            self::markTestSkipped('ext-zstd has no streaming calls.');
        }

        // the streaming encoder begins a frame before it knows the size, and states none
        $context = zstd_compress_init();
        self::assertNotFalse($context);
        $frame = zstd_compress_add($context, self::text(2_048), false).zstd_compress_add($context, '', true);
        self::assertSame(0, \ord($frame[4]) & 0xE0, 'the header holds no content size');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('states no content size or one out of bounds');

        (new ZstdMarshaller(new PassThroughMarshaller()))->unmarshall($frame);
    }

    public function testAFrameStatingASizeOfZeroIsRefused(): void
    {
        // marshall() never writes one: an empty value is shorter than any threshold
        $frame = zstd_compress('');
        self::assertIsString($frame);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('states no content size or one out of bounds');

        (new ZstdMarshaller(new PassThroughMarshaller()))->unmarshall($frame);
    }

    public function testAFrameWhoseHeaderSetsTheReservedBitIsRefused(): void
    {
        $marshaller = new ZstdMarshaller(new PassThroughMarshaller());
        $stored = self::stored($marshaller, self::text(5_000));
        $reserved = substr($stored, 0, 4).\chr(\ord($stored[4]) | 0x08).substr($stored, 5);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('sets the reserved bit');

        $marshaller->unmarshall($reserved);
    }

    public function testBytesAfterTheFrameAreRefused(): void
    {
        $marshaller = new ZstdMarshaller(new PassThroughMarshaller());
        $stored = self::stored($marshaller, self::text(5_000));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('not exactly one whole frame');

        $marshaller->unmarshall($stored.'garbage');
    }

    public function testASecondFrameIsRefused(): void
    {
        $marshaller = new ZstdMarshaller(new PassThroughMarshaller());
        $stored = self::stored($marshaller, self::text(5_000));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('not exactly one whole frame');

        $marshaller->unmarshall($stored.$stored);
    }

    public function testASkippableFrameAfterTheFrameIsRefused(): void
    {
        $marshaller = new ZstdMarshaller(new PassThroughMarshaller());
        $stored = self::stored($marshaller, self::text(5_000));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('not exactly one whole frame');

        $marshaller->unmarshall($stored.self::SKIPPABLE);
    }

    public function testAFrameCarryingAChecksumReadsBack(): void
    {
        // zstd_compress() writes no checksum, other encoders do: the low four
        // bytes of the value's XXH64, little-endian, after the last block
        $value = self::text(1_000);
        $frame = self::MAGIC."\xA4".pack('V', \strlen($value)) // a four-byte size, a single segment, a checksum
            .substr(pack('V', \strlen($value) << 3 | 1), 0, 3).$value // one raw block, the last
            .strrev(substr(hash('xxh64', $value, true), 4));

        self::assertSame($value, zstd_uncompress($frame), 'the extension reads it');
        self::assertSame($value, (new ZstdMarshaller(new PassThroughMarshaller()))->unmarshall($frame));
    }

    public function testAnEightByteSizeIsReadInTwoHalves(): void
    {
        // zstd_compress() writes an eight-byte size only past 4 GiB: a frame
        // stating $upper * 4 GiB plus the value's length, one raw block, the last
        $value = self::text(1_000);
        $frame = static fn (int $upper): string => self::MAGIC."\xE0".pack('VV', \strlen($value), $upper) // an eight-byte size, a single segment
            .substr(pack('V', \strlen($value) << 3 | 1), 0, 3).$value;
        $marshaller = new ZstdMarshaller(new PassThroughMarshaller(), maxLength: \PHP_INT_MAX);

        self::assertSame($value, zstd_uncompress($frame(0)), 'the extension reads it');
        self::assertSame($value, $marshaller->unmarshall($frame(0)), 'an upper half of zero: the lower half is the size');

        // refused before anything is inflated, and with no Error on a 32-bit PHP
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('states no content size or one out of bounds');

        $marshaller->unmarshall($frame(1));
    }

    public function testAValueBeginningWithASkippableFrameIsNotReadAsAFrame(): void
    {
        // only the zstd magic number marks a frame, so the frame behind is not inflated
        $inner = new PassThroughMarshaller();
        $value = self::SKIPPABLE.self::stored(new ZstdMarshaller($inner), self::text(5_000));

        self::assertSame($value, (new ZstdMarshaller($inner))->unmarshall($value));
        self::assertSame([$value], $inner->read);
    }

    public function testAValueLongerThanTheCapIsStoredRaw(): void
    {
        $long = self::text(5_000);

        $stored = (new ZstdMarshaller(new PassThroughMarshaller(), maxLength: 4_096))->marshall(['k' => $long], $failed);

        self::assertSame($long, $stored['k']);
        self::assertSame([], $failed);
    }

    public function testARawValueBeginningWithTheMagicAndLongerThanTheCapFails(): void
    {
        $stored = (new ZstdMarshaller(new PassThroughMarshaller(), maxLength: 4_096))->marshall(['k' => self::MAGIC.self::text(5_000)], $failed);

        self::assertSame([], $stored, 'stored raw, it would be read as a frame; compressed, it would be refused');
        self::assertSame(['k'], $failed);
    }

    public function testWhatTheInnerMarshallerFailedStaysFailed(): void
    {
        $stored = (new ZstdMarshaller(new DefaultMarshaller()))->marshall(['bad' => static fn (): null => null, 'good' => self::model()], $failed);

        self::assertSame(['bad'], $failed);
        self::assertSame(['good'], array_keys($stored));
    }

    public function testTheLevelIsChecked(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ZstdMarshaller(new DefaultMarshaller(), level: 23);
    }

    public function testTheThresholdIsChecked(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ZstdMarshaller(new DefaultMarshaller(), threshold: -1);
    }

    public function testTheCapIsChecked(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ZstdMarshaller(new DefaultMarshaller(), maxLength: 0);
    }

    public function testABadArgumentThrowsSymfonysOwnException(): void
    {
        try {
            new ZstdMarshaller(new DefaultMarshaller(), level: 23);
            self::fail('A bad level must throw.');
        } catch (InvalidArgumentException $exception) {
            // the class Symfony's adapters throw, not one of this package: artigo/cache-stampede owns
            // Artigo\Cache\Exception\, and two packages defining one class make Composer warn
            self::assertSame(InvalidArgumentException::class, $exception::class);
        }
    }

    public function testThroughTheAdapterAnItemIsAFrameOnTheServer(): void
    {
        $redis = Connection::openPrimaries();
        Connection::sweep($redis, 'zstd*');

        try {
            $pool = new VersionedRedisTagAwareAdapter($redis, 'zstd', marshaller: new ZstdMarshaller(new DefaultMarshaller()));
            $model = self::model();

            // a miss, then the write that follows it: the guarded script
            $item = $pool->getItem('model');
            self::assertFalse($item->isHit());
            self::assertTrue($pool->save($item->set($model)->tag('models')));

            $field = $redis->hGet('zstd:model', 'v');
            self::assertIsString($field);
            self::assertStringStartsWith(self::MAGIC, $field, 'the value field holds the frame');

            $read = (new VersionedRedisTagAwareAdapter($redis, 'zstd', marshaller: new ZstdMarshaller(new DefaultMarshaller())))->getItem('model');
            self::assertTrue($read->isHit());
            self::assertSame($model, $read->get());
        } finally {
            Connection::sweep($redis, 'zstd*');
        }
    }

    /**
     * What $marshaller stores for $value.
     *
     * @param array<mixed>|null $failed
     */
    private static function stored(MarshallerInterface $marshaller, mixed $value, ?array &$failed = null): string
    {
        $stored = $marshaller->marshall(['k' => $value], $failed)['k'] ?? null;
        self::assertIsString($stored);

        return $stored;
    }

    /**
     * $length bytes of varied words, which compress but not to nothing.
     */
    private static function text(int $length): string
    {
        $text = '';

        for ($i = 0; \strlen($text) < $length; ++$i) {
            $text .= 'word'.(crc32((string) $i) % 10_000).' ';
        }

        return substr($text, 0, $length);
    }

    /**
     * A serialized product model of about 2.5 KB: text, URLs, identifiers.
     *
     * @return array<string, mixed>
     */
    private static function model(): array
    {
        $variants = [];

        for ($i = 0; $i < 12; ++$i) {
            $variants[] = ['sku' => \sprintf('SKU-%05d-%02d', 4711, $i), 'price' => 1_999 + 100 * $i, 'url' => 'https://shop.example/products/4711/variant-'.$i, 'stock' => $i % 3];
        }

        return [
            'id' => 4711,
            'name' => 'Lightweight waterproof hiking jacket',
            'description' => str_repeat('A breathable shell for wet days on the trail, with taped seams and a hood that fits over a helmet. ', 6),
            'variants' => $variants,
        ];
    }
}
