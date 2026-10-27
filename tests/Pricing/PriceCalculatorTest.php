<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceNotFound;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceRow;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceSource;

class PriceCalculatorTest extends TestCase
{
    public function testUsesTheListPriceWhenNothingElseApplies(): void
    {
        $calculator = $this->calculatorWith([
            new PriceRow('10.0000', 'EUR', null, '1.0000'),
        ]);

        $price = $calculator->calculate(1, 'EUR', [], '1', 1_757_000_000);

        self::assertSame('10.0000', $price->net);
        self::assertSame(PriceSource::List, $price->source);
    }

    public function testAScalePriceBeatsTheListPriceAtItsThreshold(): void
    {
        $calculator = $this->calculatorWith([
            new PriceRow('10.0000', 'EUR', null, '1.0000'),
            new PriceRow('9.0000', 'EUR', null, '10.0000'),
        ]);

        $price = $calculator->calculate(1, 'EUR', [], '10', 1_757_000_000);

        self::assertSame('9.0000', $price->net);
        self::assertSame(PriceSource::Scale, $price->source);
    }

    public function testAScalePriceDoesNotApplyBelowItsThreshold(): void
    {
        $calculator = $this->calculatorWith([
            new PriceRow('10.0000', 'EUR', null, '1.0000'),
            new PriceRow('9.0000', 'EUR', null, '10.0000'),
        ]);

        $price = $calculator->calculate(1, 'EUR', [], '9', 1_757_000_000);

        self::assertSame('10.0000', $price->net);
        self::assertSame(PriceSource::List, $price->source);
    }

    public function testACustomerGroupPriceBeatsAGeneralOneAtTheSameQuantity(): void
    {
        $calculator = $this->calculatorWith([
            new PriceRow('10.0000', 'EUR', null, '1.0000'),
            new PriceRow('8.0000', 'EUR', 5, '1.0000'),
        ]);

        $price = $calculator->calculate(1, 'EUR', [5], '1', 1_757_000_000);

        self::assertSame('8.0000', $price->net);
        self::assertSame(PriceSource::CustomerGroup, $price->source);
    }

    public function testACustomerGroupPriceStillWinsWhenAGeneralScalePriceIsCheaper(): void
    {
        // The cheaper number does not decide — specificity does. Otherwise a
        // group price could be silently overridden by a bulk discount, and the
        // notice shown to the customer would name the wrong reason.
        $calculator = $this->calculatorWith([
            new PriceRow('7.0000', 'EUR', null, '10.0000'),
            new PriceRow('8.0000', 'EUR', 5, '10.0000'),
        ]);

        $price = $calculator->calculate(1, 'EUR', [5], '10', 1_757_000_000);

        self::assertSame('8.0000', $price->net);
        self::assertSame(PriceSource::CustomerGroup, $price->source);
    }

    public function testBeiZweiGruppenGewinntDerGuenstigerePreis(): void
    {
        // C-4: tl_member.groups is a
        // multiple selection, and the order of the ticks in it is not a
        // price argument. So the cheapest — here the more expensive row deliberately
        // comes first, so that "the first one wins" would be exposed.
        $calculator = $this->calculatorWith([
            new PriceRow('9.0000', 'EUR', 5, '1.0000'),
            new PriceRow('8.0000', 'EUR', 7, '1.0000'),
            new PriceRow('10.0000', 'EUR', null, '1.0000'),
        ]);

        $price = $calculator->calculate(1, 'EUR', [5, 7], '1', 1_757_000_000);

        self::assertSame('8.0000', $price->net);
        self::assertSame(PriceSource::CustomerGroup, $price->source);
    }

    public function testDieReihenfolgeDerGruppenAendertDenPreisNicht(): void
    {
        // The same rows, the groups asked the other way round. A resolution
        // that depended on the input order would give 9.0000 here.
        $rows = [
            new PriceRow('9.0000', 'EUR', 5, '1.0000'),
            new PriceRow('8.0000', 'EUR', 7, '1.0000'),
        ];

        self::assertSame(
            $this->calculatorWith($rows)->calculate(1, 'EUR', [5, 7], '1', 1_757_000_000)->net,
            $this->calculatorWith($rows)->calculate(1, 'EUR', [7, 5], '1', 1_757_000_000)->net,
        );
    }

    public function testEineZweiteMitgliedschaftMachtNichtsTeurer(): void
    {
        /*
         * ⚠️ Originally the expectation here was 9.0000, with
         * the reasoning "first the threshold, then the price". The reasoning
         * holds within ONE group, where the operator maintains both rows —
         * across groups nobody maintains the intersection.
         *
         * Group 5 flat 8.00 from 1, group 7 then 9.00 from 10. A member
         * of both would pay 8.00 for 9 pieces and 9.00 for 10 pieces: the second
         * membership was a price increase, and the test cemented it.
         */
        $calculator = $this->calculatorWith([
            new PriceRow('8.0000', 'EUR', 5, '1.0000'),
            new PriceRow('9.0000', 'EUR', 7, '10.0000'),
        ]);

        self::assertSame('8.0000', $calculator->calculate(1, 'EUR', [5, 7], '10', 1_757_000_000)->net);
        // And without the second group the same price — that is the point.
        self::assertSame('8.0000', $calculator->calculate(1, 'EUR', [5], '10', 1_757_000_000)->net);
    }

    public function testInnerhalbEinerGruppeSchlaegtDieHoehereSchwelleWeiterhin(): void
    {
        // The rule from above, untouched: where the operator maintains both rows,
        // the scale is a statement and not a coincidence. Otherwise
        // a scale that raises the price would have no effect.
        $calculator = $this->calculatorWith([
            new PriceRow('8.0000', 'EUR', 5, '1.0000'),
            new PriceRow('9.0000', 'EUR', 5, '10.0000'),
        ]);

        self::assertSame('9.0000', $calculator->calculate(1, 'EUR', [5], '10', 1_757_000_000)->net);
    }

    public function testDieAufloesungHaengtNichtAnDerZeilenreihenfolge(): void
    {
        /*
         * The second reason for resolving per group: a mixed rule
         * ("for different groups the amount, otherwise the threshold") is
         * not transitive. The same three rows would then give 8.00 or 9.00 depending
         * on the order of the database output — and which one that is
         * is decided by the engine.
         */
        $rows = [
            new PriceRow('8.0000', 'EUR', 5, '1.0000'),
            new PriceRow('9.0000', 'EUR', 5, '10.0000'),
            new PriceRow('8.5000', 'EUR', 7, '1.0000'),
        ];

        $expected = $this->calculatorWith($rows)->calculate(1, 'EUR', [5, 7], '10', 1_757_000_000)->net;

        foreach ([[2, 0, 1], [1, 2, 0], [2, 1, 0], [0, 2, 1]] as $order) {
            $shuffled = array_map(static fn (int $i): PriceRow => $rows[$i], $order);

            self::assertSame(
                $expected,
                $this->calculatorWith($shuffled)->calculate(1, 'EUR', [5, 7], '10', 1_757_000_000)->net,
                implode(',', $order),
            );
        }

        // 8.50 from group 7 — group 5 resolves to 9.00 on its own (scale),
        // and across the groups the cheaper one wins.
        self::assertSame('8.5000', $expected);
    }

    public function testEineAnwendbareGruppenzeileSchlaegtJedeAllgemeine(): void
    {
        // ⚠️ This test used to be called "the rows for everyone
        // go into every group resolution" — and so checked an intention
        // without observable effect: a group row beats every general one,
        // so leaving them out would have changed nothing. What it really checks is this,
        // and that is the rule from block-a 5.1.
        $calculator = $this->calculatorWith([
            new PriceRow('10.0000', 'EUR', null, '1.0000'),
            new PriceRow('7.0000', 'EUR', null, '100.0000'),
            new PriceRow('9.0000', 'EUR', 5, '1.0000'),
        ]);

        // At 1: the group row, although there is no cheaper general one.
        self::assertSame('9.0000', $calculator->calculate(1, 'EUR', [5], '1', 1_757_000_000)->net);

        /*
         * ⚠️ At 100 the group row as well — 9.00, while a GUEST pays
         * 7.00 there. That is the order from block-a 5.1 ("specificity
         * decides, not the smaller amount"), and it has remained
         * untouched here: a bulk discount must not silently override a group price,
         * otherwise the notice to the customer names the
         * wrong reason.
         *
         * It is the same flavour as the case of two groups further up, only
         * between group and list price instead of between two groups — and
         * unlike there an explicitly made decision. Whether it should
         * stay that way is not this test's question; it only pins down
         * what applies today.
         */
        self::assertSame('9.0000', $calculator->calculate(1, 'EUR', [5], '100', 1_757_000_000)->net);
    }

    public function testZweiZeilenDerselbenGruppeBleibenUnentschieden(): void
    {
        // ⚠️ The limit of the rule above, and it is the point: two rows
        // of ONE group on one threshold are a tie that
        // DbalPriceTieIssueSource reports to the operator. If the cheaper one were silently
        // chosen here, the finding would vanish from the world — the shop
        // would sell at a price from a duplicate row nobody would ever
        // hear about again.
        //
        // What is checked is therefore that the FIRST one wins, as before C-4: the
        // row the database delivers first.
        $calculator = $this->calculatorWith([
            new PriceRow('9.0000', 'EUR', 5, '1.0000'),
            new PriceRow('8.0000', 'EUR', 5, '1.0000'),
        ]);

        self::assertSame('9.0000', $calculator->calculate(1, 'EUR', [5], '1', 1_757_000_000)->net);
    }

    public function testTheHighestMatchingThresholdWins(): void
    {
        $calculator = $this->calculatorWith([
            new PriceRow('10.0000', 'EUR', null, '1.0000'),
            new PriceRow('9.0000', 'EUR', null, '10.0000'),
            new PriceRow('8.0000', 'EUR', null, '100.0000'),
        ]);

        $price = $calculator->calculate(1, 'EUR', [], '150', 1_757_000_000);

        self::assertSame('8.0000', $price->net);
    }

    public function testHandlesFractionalQuantitiesForGoodsSoldByLength(): void
    {
        // 0.1 m steps: a scale price from 5 m must not apply at 4.9 m.
        $calculator = $this->calculatorWith([
            new PriceRow('10.0000', 'EUR', null, '1.0000'),
            new PriceRow('9.0000', 'EUR', null, '5.0000'),
        ]);

        self::assertSame('10.0000', $calculator->calculate(1, 'EUR', [], '4.9', 1_757_000_000)->net);
        self::assertSame('9.0000', $calculator->calculate(1, 'EUR', [], '5.0', 1_757_000_000)->net);
    }

    public function testThrowsWhenNoPriceApplies(): void
    {
        $calculator = $this->calculatorWith([]);

        $this->expectException(PriceNotFound::class);

        $calculator->calculate(1, 'EUR', [], '1', 1_757_000_000);
    }

    public function testThrowsRatherThanReturningZero(): void
    {
        // A scale price from 10 with no base row: at quantity 1 nothing applies.
        $calculator = $this->calculatorWith([
            new PriceRow('9.0000', 'EUR', null, '10.0000'),
        ]);

        $this->expectException(PriceNotFound::class);

        $calculator->calculate(1, 'EUR', [], '1', 1_757_000_000);
    }

    /**
     * @param list<PriceRow> $rows
     */
    private function calculatorWith(array $rows): PriceCalculator
    {
        $repository = $this->createMock(PriceRepositoryInterface::class);
        $repository->method('findCandidates')->willReturn($rows);

        return new PriceCalculator($repository);
    }
}
