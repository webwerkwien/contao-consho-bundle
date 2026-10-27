<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Pricing;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceCalculator;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceRepositoryInterface;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceRow;
use Webwerkwien\ContaoConshoBundle\Pricing\PriceSource;

/**
 * Block H, H-1: promotion, tie, promotion against scale and group
 * (Block H, H1–H3, A, B).
 *
 * Every case in both row orders, wherever the order could change
 * something — the database delivers without ORDER BY.
 */
class PromotionPriceTest extends TestCase
{
    private const NOW = 1_800_000_000;

    /**
     * @return iterable<string, array{bool}>
     */
    public static function orders(): iterable
    {
        yield 'wie angelegt' => [false];
        yield 'umgekehrt' => [true];
    }

    #[DataProvider('orders')]
    public function testEineGuenstigereBefristeteZeileIstEineAktion(bool $reversed): void
    {
        $price = $this->calculate([
            $this->standing('10.0000'),
            $this->windowed('8.0000', from: self::NOW - 100, to: self::NOW + 100),
        ], reversed: $reversed);

        self::assertSame('8.0000', $price->net);
        self::assertSame(PriceSource::Promotion, $price->source);
    }

    #[DataProvider('orders')]
    public function testEineTeurereBefristeteZeileIstDerNeuePreisOhneAktion(bool $reversed): void
    {
        // B: a planned price increase — applies, but does not interfere.
        $price = $this->calculate([
            $this->standing('10.0000'),
            $this->windowed('12.0000', from: self::NOW - 100),
        ], reversed: $reversed);

        self::assertSame('12.0000', $price->net);
        self::assertSame(PriceSource::List, $price->source);
    }

    public function testEineBefristeteZeileAlleinIstKeineAktion(): void
    {
        $price = $this->calculate([$this->windowed('8.0000', to: self::NOW + 100)]);

        self::assertSame('8.0000', $price->net);
        self::assertSame(PriceSource::List, $price->source);
    }

    #[DataProvider('orders')]
    public function testVonZweiBefristetenGewinntDieSpaeterBeginnende(bool $reversed): void
    {
        $price = $this->calculate([
            $this->standing('10.0000'),
            $this->windowed('7.0000', from: self::NOW - 500),
            $this->windowed('9.0000', from: self::NOW - 100),
        ], reversed: $reversed);

        self::assertSame('9.0000', $price->net);
        self::assertSame(PriceSource::Promotion, $price->source, 'verglichen mit der stehenden Zeile, nicht mit der geschlagenen befristeten');
    }

    #[DataProvider('orders')]
    public function testDieAktionGiltAuchUeberEineTeurereStaffel(bool $reversed): void
    {
        // A: otherwise whoever buys 10 would pay more per piece than whoever buys 1.
        $price = $this->calculate([
            $this->standing('10.0000'),
            $this->standing('9.5000', '10.0000'),
            $this->windowed('9.0000', from: self::NOW - 100),
        ], quantity: '10', reversed: $reversed);

        self::assertSame('9.0000', $price->net);
        self::assertSame(PriceSource::Promotion, $price->source);
    }

    #[DataProvider('orders')]
    public function testEineGuenstigereStaffelSchlaegtDieAktion(bool $reversed): void
    {
        $price = $this->calculate([
            $this->standing('10.0000'),
            $this->standing('8.0000', '10.0000'),
            $this->windowed('9.0000', from: self::NOW - 100),
        ], quantity: '10', reversed: $reversed);

        self::assertSame('8.0000', $price->net);
        self::assertSame(PriceSource::Scale, $price->source);
    }

    public function testEineTeurereBefristeteZeileWirktNichtUeberDieStaffel(): void
    {
        // A price increase is not a promotion — A applies only to promotions.
        $price = $this->calculate([
            $this->standing('10.0000'),
            $this->standing('9.5000', '10.0000'),
            $this->windowed('12.0000', from: self::NOW - 100),
        ], quantity: '10');

        self::assertSame('9.5000', $price->net);
        self::assertSame(PriceSource::Scale, $price->source);
    }

    #[DataProvider('orders')]
    public function testEineAktionFuerAlleSchlaegtEinenTeurerenGruppenpreis(bool $reversed): void
    {
        // H3: a member never pays more than a guest because of a promotion.
        $price = $this->calculate([
            $this->standing('10.0000'),
            $this->windowed('7.0000', from: self::NOW - 100),
            $this->standing('8.0000', group: 5),
        ], groups: [5], reversed: $reversed);

        self::assertSame('7.0000', $price->net);
        self::assertSame(PriceSource::Promotion, $price->source);
        self::assertNull($price->customerGroup);
    }

    #[DataProvider('orders')]
    public function testEinGuenstigererGruppenpreisSchlaegtDieAktion(bool $reversed): void
    {
        $price = $this->calculate([
            $this->standing('10.0000'),
            $this->windowed('7.0000', from: self::NOW - 100),
            $this->standing('6.0000', group: 5),
        ], groups: [5], reversed: $reversed);

        self::assertSame('6.0000', $price->net);
        self::assertSame(PriceSource::CustomerGroup, $price->source);
        self::assertSame(5, $price->customerGroup);
    }

    public function testEinStehenderListenpreisTrittWeiterNichtGegenDieGruppeAn(): void
    {
        // Unchanged since C-4: specificity within the relation — only the promotion competes.
        $price = $this->calculate([
            $this->standing('7.0000'),
            $this->standing('8.0000', group: 5),
        ], groups: [5]);

        self::assertSame('8.0000', $price->net);
        self::assertSame(PriceSource::CustomerGroup, $price->source);
    }

    #[DataProvider('orders')]
    public function testEineAktionInnerhalbDerGruppe(bool $reversed): void
    {
        $price = $this->calculate([
            $this->standing('10.0000'),
            $this->standing('8.0000', group: 5),
            $this->windowed('6.0000', from: self::NOW - 100, group: 5),
        ], groups: [5], reversed: $reversed);

        self::assertSame('6.0000', $price->net);
        self::assertSame(PriceSource::Promotion, $price->source);
        self::assertSame(5, $price->customerGroup);
    }

    public function testDerGastSiehtDieAktionEinerGruppeNicht(): void
    {
        $price = $this->calculate([
            $this->standing('10.0000'),
            $this->windowed('6.0000', from: self::NOW - 100, group: 5),
        ]);

        self::assertSame('10.0000', $price->net);
        self::assertSame(PriceSource::List, $price->source);
    }

    /**
     * @param list<PriceRow> $rows
     * @param list<int>      $groups
     */
    private function calculate(array $rows, string $quantity = '1', array $groups = [], bool $reversed = false): \Webwerkwien\ContaoConshoBundle\Pricing\ResolvedPrice
    {
        $repository = $this->createStub(PriceRepositoryInterface::class);

        // Like the database: the guest gets no group rows.
        $rows = array_values(array_filter($rows, static fn (PriceRow $row): bool => null === $row->customerGroup || \in_array($row->customerGroup, $groups, true)));
        $repository->method('findCandidates')->willReturn($reversed ? array_reverse($rows) : $rows);

        return (new PriceCalculator($repository))->calculate(1, 'EUR', $groups, $quantity, self::NOW);
    }

    private function standing(string $net, string $threshold = '1.0000', int|null $group = null): PriceRow
    {
        return new PriceRow($net, 'EUR', $group, $threshold);
    }

    private function windowed(string $net, int|null $from = null, int|null $to = null, string $threshold = '1.0000', int|null $group = null): PriceRow
    {
        return new PriceRow($net, 'EUR', $group, $threshold, $from, $to);
    }
}
