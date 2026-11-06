<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests\Adapter;

use Artigo\Cache\Adapter\MissRecords;
use PHPUnit\Framework\TestCase;

/**
 * The records a pool keeps of the reads a write may follow: bounded, oldest
 * first, and moved on only by the rule that came right after what they saw.
 */
final class MissRecordsTest extends TestCase
{
    public function testThePoolRemembersAThousandReadsAndForgetsTheOldestFirst(): void
    {
        $records = new MissRecords();

        for ($i = 0; $i <= MissRecords::CAP; ++$i) {
            $records->take('k'.$i, '1-0', '');
        }

        self::assertSame(MissRecords::CAP, $records->count());
        self::assertNull($records->of('k0'), 'the oldest went');
        self::assertSame(['1-0', ''], $records->of('k1'));
        self::assertSame(['1-0', ''], $records->of('k'.MissRecords::CAP));
    }

    public function testAReadTakenAgainIsTheNewest(): void
    {
        $records = new MissRecords();
        $records->take('again', '1-0', 'a');

        for ($i = 1; $i < MissRecords::CAP; ++$i) {
            $records->take('k'.$i, '1-0', '');
        }

        $records->take('again', '2-0', 'b');
        $records->take('one-more', '2-0', '');

        self::assertSame(['2-0', 'b'], $records->of('again'), 'replaced, and kept as the newest');
        self::assertNull($records->of('k1'), 'the oldest went instead');
    }

    public function testABatchIsRememberedWholeUntilTheRecordsAreCleared(): void
    {
        $records = new MissRecords();
        $records->reserve(1_500);

        for ($i = 0; $i < 2_500; ++$i) {
            $records->take('k'.$i, '1-0', '');
        }

        self::assertSame(2_500, $records->count(), 'the floor, and room for the batch on top');
        self::assertNotNull($records->of('k0'));

        $records->clear();

        for ($i = 0; $i <= MissRecords::CAP; ++$i) {
            $records->take('k'.$i, '1-0', '');
        }

        self::assertSame(MissRecords::CAP, $records->count(), 'clear() gives the room back');
    }

    public function testOnlyTheReadsThatSawTheStreamUpToTheRuleMoveOn(): void
    {
        $records = new MissRecords();
        $records->take('up-to-it', '5-0', 'a');
        $records->take('behind-it', '4-0', 'b');

        $records->restamp('5-0', '6-0');

        self::assertSame(['6-0', 'a'], $records->of('up-to-it'));
        self::assertSame(['4-0', 'b'], $records->of('behind-it'), 'a rule stands between it and ours');
    }
}
