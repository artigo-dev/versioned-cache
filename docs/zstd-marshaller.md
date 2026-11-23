# ZstdMarshaller: compress in the marshaller, never in the client

Back to the [README](../README.md#zstdmarshaller-compress-in-the-marshaller-never-in-the-client).

Run again on 2.2.4 on 2026-11-23 (PHP 8.5.12, ext-zstd 0.18.0 with libzstd
1.5.7, symfony/cache 8.1.11, Redis 8.10.1; the medians of five runs): every
number on this page stayed within the spread between runs or within 20 % of
the new middle value.

**The setup.** The adapter writes raw hash fields and reads them back with
`HMGET`, so it refuses phpredis' own serializer and compression, where
`RedisTagAwareAdapter` refuses the compression alone. The marshaller is where
compression belongs, and it pays: bastnic measured 2 609 → 743 bytes per
model and about 80 % less load on the cache nodes
([his post](https://gist.github.com/bastnic/b08fa600b19d24b2c839461c700f6cf3)).

**The question.** Deflate or zstd, and how does a read tell a compressed value
from a raw one?

**Example.** A ~2.6 KB product model. Deflate makes it 0.482 of its size in
56 µs and inflates it in 14 µs. Zstd makes it 0.508 of its size in 24 µs and
inflates it in 6.2 µs. A ~300-byte record: deflate 0.661 in 11 µs, zstd 0.723
in 7.4 µs. A 100-byte value is not worth compressing at all: a frame is
smaller than the value only from about 90 bytes on. Through the adapter, a
~2.4 KB entity (2 386 bytes serialized, `bench.php payload=model`) goes out as:

- **`DefaultMarshaller`:** 2 912 bytes on the wire per item written, nothing
  to decide on read.
- **`DeflateMarshaller`:** 1 329 bytes (−54 %); a read tries to inflate every
  value, 330 ns when the value was not deflated.
- **`ZstdMarshaller`:** 1 376 bytes (−53 %); a read tells a frame from a raw
  value by its first four bytes, a 4-byte compare (30 ns), which is what lets
  it store a value raw when a frame would not pay.

So zstd puts about 4 % more bytes on the wire than deflate for the entity, 5 %
more on the models, and takes less than half of its time to write them and to
read them; where
ext-zstd is loaded, use this package's `ZstdMarshaller`, elsewhere Symfony's
`DeflateMarshaller`. Either works with any Symfony pool:

```php
use Artigo\Cache\Adapter\VersionedRedisTagAwareAdapter;
use Artigo\Cache\Marshaller\ZstdMarshaller;
use Symfony\Component\Cache\Marshaller\DefaultMarshaller;

$pool = new VersionedRedisTagAwareAdapter($redis, marshaller: new ZstdMarshaller(new DefaultMarshaller()));
```

```yaml
# config/services.yaml: every pool that uses the default marshaller
services:
    Artigo\Cache\Marshaller\ZstdMarshaller:
        decorates: cache.default_marshaller
        arguments: ['@.inner']
```

Since FrameworkBundle 8.1 a single pool can take it with its `marshaller`
option instead.

**Measured.** For a ~2.4 KB entity, 2 386 bytes serialized
(`bench.php stores=versioned payload=model`, without a marshaller option, then
with `marshaller=deflate` and `marshaller=zstd`):

| marshaller | bytes Redis received per item written | commands |
|---|---:|---:|
| `DefaultMarshaller` | 2 912 B | 5 per item |
| `DeflateMarshaller` | **1 329 B** (−54 %) | 5 per item |
| `ZstdMarshaller` | **1 376 B** (−53 %) | 5 per item |

The bytes are the whole write — the `HMGET` that found the miss and the
`EVALSHA` that carries the value, its tags, its watermark and the epochs; the
five commands count the three the script runs — so the value itself shrank by
more than that.

On the ~2.6 KB models zstd keeps 5 % more bytes and takes less than half of
deflate's time to write them and to read them; on ~300-byte records it keeps
9 % more, takes two thirds of the time to write them and the same time to read
them; on a large fragment it is the same size at an eighth of the write time
(`benchmarks/marshaller.php`, PHP 8.5.12, ext-zstd 0.18.0 with libzstd 1.5.7,
the medians of three runs):

| values | deflate (level -1): ratio · compress · decompress | zstd (level 3): ratio · compress · decompress |
|---|---|---|
| product models, ~2.6 KB | 0.482 · 56 µs · 14 µs | 0.508 · 24 µs · 6.2 µs |
| records, ~300 B | 0.661 · 11 µs · 3.1 µs | 0.723 · 7.4 µs · 3.2 µs |
| a rendered fragment, ~50 KB | 0.135 · 701 µs · 28 µs | 0.136 · 93 µs · 26 µs |

**How it works.** A zstd frame begins with a four-byte magic number, so
`ZstdMarshaller` tells a compressed value from a raw one with a 4-byte compare
(30 ns), where `DeflateMarshaller` tries to inflate every value it reads
(330 ns when the value was not deflated). That is what lets it keep a value
raw: one shorter than the threshold, or one the frame would not shrink. A
frame is smaller than the value only from about 90 bytes on, and around 100
bytes it saves 4 %, so values under 256 bytes stay raw by default
(`threshold`). A raw value that happens to begin with the magic number is
compressed whatever its size, so whatever the marshaller writes and begins
with it is a frame; such a value longer than `maxLength` (16 MiB) cannot be
stored either way and is reported as failed, as the inner marshaller reports
a value it cannot serialize. The constructor takes the zstd `level` (1 to 22;
3, zstd's own default), the `threshold` and `maxLength`.

**Caveats.** Values written before the marshaller was added read as they are,
unless one begins with the magic number: any stored value that does is read
as a frame. A value `DeflateMarshaller` wrote has no header and reads as a
miss (Symfony's own adapters log the failed unserialize), so moving a pool
from one to the other is a cold cache. A value that begins with the magic
number is read as exactly one frame, the way the marshaller writes it, and
refused, so read as a miss, where it is anything else: where the header sets
the reserved bit; where it states no size, a size of 0, 4 GiB or more, or more
than `maxLength` (16 MiB; longer values are stored raw), checked before
inflating because the extension reserves the stated size first; where the frame's
blocks, followed to the last one, do not end exactly where the value does (a
frame cut short, a second frame, a skippable frame or any other bytes after
it), which `zstd_uncompress()` alone would not notice; and where the frame
does not inflate to the size it states. Frames carry no checksum: bytes
damaged inside one can inflate to a different value of the right size, as
with deflate.
