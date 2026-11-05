<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Marshaller;

use Artigo\Cache\Exception\InvalidArgumentException;
use Symfony\Component\Cache\Exception\CacheException;
use Symfony\Component\Cache\Marshaller\MarshallerInterface;

/**
 * Compresses values with zstd, around any other marshaller.
 *
 *     new ZstdMarshaller(new DefaultMarshaller())
 *
 * A zstd frame starts with a magic number, so a read tells a compressed value
 * from a raw one by its first four bytes, where DeflateMarshaller has to try
 * inflating every value. That is what lets a value stay raw: one shorter than
 * $threshold, or one the frame would not shrink, is stored as the inner
 * marshaller wrote it. A raw value that happens to begin with the magic number
 * is compressed whatever its size, so whatever this marshaller writes and
 * begins with it is a frame.
 *
 * Values the pool held before this marshaller was added read as they are,
 * unless one begins with the magic number: any stored value that does is read
 * as a frame. A value DeflateMarshaller wrote has no header and reaches the
 * inner marshaller still deflated, which refuses it: a miss, never a wrong
 * value, so moving a pool from one to the other is a cold cache.
 *
 * A value that begins with the magic number is read as exactly one frame, the
 * way this marshaller writes it, and refused, so the adapter reads a miss,
 * where it is anything else: where the header sets the reserved bit; where it
 * states no content size, a size of 0 or one above $maxLength
 * (zstd_uncompress() allocates what the header states before it inflates
 * anything); where the frame's blocks do not end exactly where the value
 * does, as with a frame cut short, a second frame, a skippable frame or any
 * other bytes after it; and where the frame does not inflate to the size it
 * states. A frame carries no checksum: bytes damaged inside it can inflate to
 * a different value of the right size.
 */
final class ZstdMarshaller implements MarshallerInterface
{
    /**
     * The four bytes every zstd frame begins with.
     */
    private const MAGIC = "\x28\xB5\x2F\xFD";

    /**
     * The most a block holds, compressed or not (RFC 8878, 3.1.1.2).
     */
    private const BLOCK_SIZE_MAX = 128 * 1024;

    /**
     * @param int $level     the zstd level, 1 to 22; 3 is zstd's own default
     * @param int $threshold values shorter than this many bytes are stored raw.
     *                       A frame is smaller than the value only from about
     *                       90 bytes on, and at 100 it saves about 4 %
     *                       (benchmarks/marshaller.php)
     * @param int $maxLength values longer than this many bytes are stored raw,
     *                       and a frame whose header states more is refused
     *                       unread
     */
    public function __construct(
        private readonly MarshallerInterface $marshaller,
        private readonly int $level = 3,
        private readonly int $threshold = 256,
        private readonly int $maxLength = 16 * 1024 * 1024,
    ) {
        if (!\function_exists('zstd_compress')) {
            throw new CacheException('The "zstd" PHP extension is not loaded.');
        }

        if ($level < 1 || $level > 22) {
            throw new InvalidArgumentException(\sprintf('Argument "$level" must be between 1 and 22, %d given.', $level));
        }

        if ($threshold < 0) {
            throw new InvalidArgumentException(\sprintf('Argument "$threshold" must not be negative, %d given.', $threshold));
        }

        if ($maxLength < 1) {
            throw new InvalidArgumentException(\sprintf('Argument "$maxLength" must be positive, %d given.', $maxLength));
        }
    }

    /**
     * @param array<mixed>      $values
     * @param array<mixed>|null $failed
     *
     * @return array<mixed>
     */
    public function marshall(array $values, ?array &$failed): array
    {
        $marshalled = $this->marshaller->marshall($values, $failed);

        foreach ($marshalled as $key => $value) {
            if (!\is_string($value)) {
                continue;
            }

            $escape = str_starts_with($value, self::MAGIC);
            $length = \strlen($value);

            if (!$escape && $length < $this->threshold) {
                continue;
            }

            // a frame stating more than the cap would be refused on read
            $frame = $length <= $this->maxLength ? zstd_compress($value, $this->level) : false;

            if (\is_string($frame) && ($escape || \strlen($frame) < $length)) {
                $marshalled[$key] = $frame;
            } elseif ($escape) {
                // stored raw, it would be read as a frame
                unset($marshalled[$key]);
                $failed[] = $key;
            }
        }

        return $marshalled;
    }

    public function unmarshall(string $value): mixed
    {
        if (str_starts_with($value, self::MAGIC)) {
            // RFC 8878, 3.1.1.1.1: a decoder refuses a frame with the reserved bit set
            if (\strlen($value) > 4 && 0 !== (\ord($value[4]) & 0x08)) {
                throw new \DomainException('Refused a zstd frame whose header sets the reserved bit.');
            }

            $size = self::contentSize($value);

            // checked before inflating: the extension reserves the stated size first
            if (null === $size || $size < 1 || $size > $this->maxLength) {
                throw new \DomainException('Refused a zstd frame that states no content size or one out of bounds.');
            }

            // marshall() writes one frame per value; zstd_uncompress() stops
            // at the end of the first frame and ignores whatever follows it
            if (self::frameLength($value) !== \strlen($value)) {
                throw new \DomainException('Refused a zstd value that is not exactly one whole frame.');
            }

            // a corrupt frame answers false, with a warning
            $inflated = @zstd_uncompress($value);

            if (!\is_string($inflated) || \strlen($inflated) !== $size) {
                throw new \DomainException('Failed to decompress a zstd frame.');
            }

            $value = $inflated;
        }

        return $this->marshaller->unmarshall($value);
    }

    /**
     * How many bytes the frame at the start of $value takes: its header,
     * every block up to the one marked last, and the checksum where the
     * header announces one (RFC 8878, 3.1.1). Null where the header is cut
     * short, or a block is of the reserved type or larger than a block may be.
     */
    private static function frameLength(string $value): ?int
    {
        if (\strlen($value) < 5) {
            return null;
        }

        $descriptor = \ord($value[4]);
        $singleSegment = 0 !== ($descriptor & 0x20);

        // magic, descriptor, window (absent in a single segment), dictionary id, content size
        $position = 5 + ($singleSegment ? 0 : 1) + [0, 1, 2, 4][$descriptor & 0x03] + [$singleSegment ? 1 : 0, 2, 4, 8][$descriptor >> 6];

        do {
            if ($position + 3 > \strlen($value)) {
                return null;
            }

            // three bytes, little-endian: Last_Block, Block_Type, Block_Size
            $header = \ord($value[$position]) | \ord($value[$position + 1]) << 8 | \ord($value[$position + 2]) << 16;
            $blockSize = $header >> 3;

            $content = match (($header >> 1) & 0x03) {
                0, 2 => $blockSize, // a raw or compressed block: Block_Size bytes follow
                1 => 1, // an RLE block: one byte, repeated Block_Size times
                default => null, // reserved
            };

            if (null === $content || $blockSize > self::BLOCK_SIZE_MAX) {
                return null;
            }

            $position += 3 + $content;
        } while (0 === ($header & 1));

        // Content_Checksum: four bytes after the last block
        return 0 !== ($descriptor & 0x04) ? $position + 4 : $position;
    }

    /**
     * The content size a frame header states, null where it states none
     * (RFC 8878, 3.1.1.1).
     */
    private static function contentSize(string $frame): ?int
    {
        if (\strlen($frame) < 5) {
            return null;
        }

        $descriptor = \ord($frame[4]);
        $singleSegment = ($descriptor >> 5) & 1;
        $sizeBytes = [$singleSegment, 2, 4, 8][$descriptor >> 6];

        if (0 === $sizeBytes) {
            return null;
        }

        // past the descriptor: the window byte, absent in a single segment,
        // and the dictionary id
        $offset = 5 + (1 - $singleSegment) + [0, 1, 2, 4][$descriptor & 0x03];

        if (\strlen($frame) < $offset + $sizeBytes) {
            return null;
        }

        $field = unpack([1 => 'C', 2 => 'v', 4 => 'V', 8 => 'P'][$sizeBytes], $frame, $offset);
        $size = \is_array($field) && \is_int($field[1] ?? null) ? $field[1] : -1;

        // a two-byte field counts from 256
        return match (true) {
            $size < 0 => null,
            2 === $sizeBytes => $size + 256,
            default => $size,
        };
    }
}
