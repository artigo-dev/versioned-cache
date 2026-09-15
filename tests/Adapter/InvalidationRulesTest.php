<?php

declare(strict_types=1);

/*
 * This file is part of the artigo/versioned-cache package.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Artigo\Cache\Tests\Adapter;

use Artigo\Cache\Adapter\InvalidationRules;
use PHPUnit\Framework\TestCase;

/**
 * The rule set is the one piece of the adapter that is pure: entries in, a
 * verdict out. What is pinned here is the shape of that verdict - only a rule
 * newer than the item's watermark counts, only the newest rule per tag has to
 * be kept for that - and how the set follows a stream that moves under it.
 */
final class InvalidationRulesTest extends TestCase
{
    public function testOnlyARuleNewerThanTheWatermarkInvalidates(): void
    {
        $rules = $this->rules();
        $rules->absorb(self::entries(['100-0' => ['t' => ['a']]]));

        self::assertTrue($rules->invalidates('ns:x', ['a'], '50-0'), 'written before the rule');
        self::assertTrue($rules->invalidates('ns:x', ['a'], '99-9'));
        self::assertFalse($rules->invalidates('ns:x', ['a'], '100-0'), 'written with the rule already in the stream');
        self::assertFalse($rules->invalidates('ns:x', ['a'], '150-0'));
        self::assertFalse($rules->invalidates('ns:x', ['b'], '0-0'), 'a tag no rule names');
    }

    public function testTheNewestRulePerTagDecides(): void
    {
        $rules = $this->rules();
        $rules->absorb(self::entries(['100-0' => ['t' => ['a']], '200-0' => ['t' => ['a', 'b']]]));

        self::assertTrue($rules->invalidates('ns:x', ['a'], '150-0'), 'the second rule for "a" is newer than this item');
        self::assertFalse($rules->invalidates('ns:x', ['a'], '200-0'));
        self::assertTrue($rules->invalidates('ns:x', ['b'], '150-0'));
    }

    public function testAPrefixRuleMatchesByIdPrefix(): void
    {
        $rules = $this->rules();
        $rules->absorb(self::entries(['100-0' => ['p' => 'ns:foo']]));

        self::assertTrue($rules->invalidates('ns:foo', [], '0-0'));
        self::assertTrue($rules->invalidates('ns:foobar', [], '0-0'), 'clearing "foo" clears "foobar" too, as Symfony\'s clear($prefix) does');
        self::assertFalse($rules->invalidates('ns:barfoo', [], '0-0'));
        self::assertFalse($rules->invalidates('ns:foo', [], '100-0'), 'written after the clear');
    }

    public function testARefreshFromTheLastIdAppendsWhatIsNew(): void
    {
        $rules = $this->rules();
        $rules->absorb(self::entries(['100-0' => ['t' => ['a']]]));
        self::assertSame('100-0', $rules->last());

        // the next range starts at 100-0 inclusively, so it comes back first
        $rules->absorb(self::entries(['100-0' => ['t' => ['a']], '200-0' => ['t' => ['b']]]));

        self::assertSame('200-0', $rules->last());
        self::assertTrue($rules->invalidates('ns:x', ['a'], '50-0'), 'the rule held before is still held');
        self::assertTrue($rules->invalidates('ns:x', ['b'], '150-0'), 'and the new one arrived');
    }

    public function testAStreamThatMovedOnReplacesWhatWasHeld(): void
    {
        $rules = $this->rules();
        $rules->absorb(self::entries(['100-0' => ['t' => ['a']]]));

        // the range from 100-0 does not start with 100-0: the server no longer
        // holds it, so what came back is the whole stream now
        $rules->absorb(self::entries(['300-0' => ['t' => ['c']]]));

        self::assertSame('300-0', $rules->last());
        self::assertFalse($rules->invalidates('ns:x', ['a'], '0-0'), 'a rule the server dropped is dropped here too');
        self::assertTrue($rules->invalidates('ns:x', ['c'], '0-0'));
    }

    public function testAnEmptyAnswerAfterHoldingRulesForgetsThem(): void
    {
        $rules = $this->rules();
        $rules->absorb(self::entries(['100-0' => ['t' => ['a']]]));

        // the stream was deleted: nothing comes back, not even what we held
        $rules->absorb([]);

        self::assertSame(InvalidationRules::NONE, $rules->last());
        self::assertFalse($rules->invalidates('ns:x', ['a'], '0-0'), 'an item written against the empty stream must not be judged by a rule that is gone');
    }

    public function testRulesOlderThanTheRetentionAreDropped(): void
    {
        $rules = new InvalidationRules('ns:', 1_000);
        $rules->absorb(self::entries(['100-0' => ['t' => ['a']], '2000-0' => ['t' => ['b']]]));

        self::assertFalse($rules->invalidates('ns:x', ['a'], '0-0'), 'nothing written before this rule can still be alive');
        self::assertTrue($rules->invalidates('ns:x', ['b'], '0-0'));
    }

    public function testNothingHeldMeansNothingInvalidates(): void
    {
        $rules = $this->rules();

        self::assertSame(InvalidationRules::NONE, $rules->last());
        self::assertFalse($rules->invalidates('ns:x', ['a'], '0-0'));
    }

    public function testAnItemStampedWithARuleTheStreamNoLongerHoldsIsStale(): void
    {
        $rules = $this->rules();
        $rules->absorb(self::entries(['100-0' => ['t' => ['a'], 'first' => true]]));

        // the stream was lost under us: evicted, deleted, gone with its slot
        $rules->absorb([]);

        self::assertTrue($rules->invalidates('ns:x', ['b'], '100-0'), 'stamped with a rule the stream has no memory of, whatever its tags');
        self::assertFalse($rules->invalidates('ns:x', ['a'], '0-0'), 'written when no rule existed: nothing lost can concern it');
    }

    public function testAStreamRebornAfterTheItemWasWrittenInvalidatesIt(): void
    {
        $rules = $this->rules();

        // what the server holds after a loss and one unrelated rule: an
        // opening entry newer than the item
        $rules->absorb(self::entries(['200-0' => ['t' => ['other'], 'first' => true]]));

        self::assertTrue($rules->invalidates('ns:x', ['a'], '100-0'), 'the item saw a rule, and the stream started over after it');
        self::assertFalse($rules->invalidates('ns:x', ['a'], '200-0'), 'written against the reborn stream');
        self::assertFalse($rules->invalidates('ns:x', ['a'], '0-0'), 'written when no rule existed: judged by tags alone');
        self::assertTrue($rules->invalidates('ns:x', ['other'], '0-0'));
    }

    public function testATrimmedStreamIsNotARebornOne(): void
    {
        $rules = $this->rules();

        // the oldest surviving rule followed others: an item stamped before
        // it is merely older than the retention window
        $rules->absorb(self::entries(['200-0' => ['t' => ['other'], 'first' => false]]));

        self::assertFalse($rules->invalidates('ns:x', ['a'], '100-0'));
    }

    public function testAnEntryThatDoesNotSayWhetherItOpenedTheStreamIsInconclusive(): void
    {
        $rules = $this->rules();

        // written by an adapter that did not record it yet
        $rules->absorb(self::entries(['200-0' => ['t' => ['other']]]));

        self::assertFalse($rules->invalidates('ns:x', ['a'], '100-0'));
    }

    public function testTheOldestEntryIsKeptAcrossARefreshAndReplacedOnAReset(): void
    {
        $rules = $this->rules();
        $rules->absorb(self::entries(['100-0' => ['t' => ['a'], 'first' => true]]));

        // a refresh from 100-0: the server still holds it, and appended one
        $rules->absorb(self::entries(['100-0' => ['t' => ['a'], 'first' => true], '200-0' => ['t' => ['b'], 'first' => false]]));

        self::assertTrue($rules->invalidates('ns:x', ['z'], '50-0'), 'still judged against the opening rule held from the first fetch');

        // the stream moved on: what came back is the whole stream, and its
        // oldest entry followed others
        $rules->absorb(self::entries(['300-0' => ['t' => ['c'], 'first' => false]]));

        self::assertFalse($rules->invalidates('ns:x', ['z'], '50-0'));
    }

    public function testTheOlderOfTwoIds(): void
    {
        self::assertSame('100-0', InvalidationRules::older('100-0', '100-1'));
        self::assertSame('100-0', InvalidationRules::older('100-1', '100-0'));
        self::assertSame('99-5', InvalidationRules::older('100-0', '99-5'));
        self::assertSame('0-0', InvalidationRules::older('0-0', '1-0'));
    }

    private function rules(): InvalidationRules
    {
        return new InvalidationRules('ns:', 2_592_000_000);
    }

    /**
     * What the client hands back for an XRANGE: id => fields, the rule JSON
     * under "t", and whether the entry opened the stream under "first" when
     * the rule says so.
     *
     * @param array<string, array<string, mixed>> $rules
     *
     * @return array<string, array<string, string>>
     */
    private static function entries(array $rules): array
    {
        $entries = [];

        foreach ($rules as $id => $rule) {
            $opened = $rule['first'] ?? null;
            unset($rule['first']);

            $entries[$id] = ['t' => json_encode($rule, \JSON_THROW_ON_ERROR)];

            if (\is_bool($opened)) {
                $entries[$id]['first'] = $opened ? '1' : '0';
            }
        }

        return $entries;
    }
}
