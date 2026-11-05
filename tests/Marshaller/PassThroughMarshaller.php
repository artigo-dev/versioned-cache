<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests\Marshaller;

use Symfony\Component\Cache\Marshaller\MarshallerInterface;

/**
 * Writes strings as they are, and remembers what it was asked to read.
 */
final class PassThroughMarshaller implements MarshallerInterface
{
    /** @var list<string> */
    public array $read = [];

    /**
     * @param array<mixed>      $values
     * @param array<mixed>|null $failed
     *
     * @param-out array<mixed> $failed
     *
     * @return array<mixed>
     */
    public function marshall(array $values, ?array &$failed): array
    {
        $failed = [];

        return array_map(static fn (mixed $value): string => \is_string($value) ? $value : serialize($value), $values);
    }

    public function unmarshall(string $value): mixed
    {
        $this->read[] = $value;

        return $value;
    }
}
