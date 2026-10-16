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
        // C-4, entschieden von Michael am 2026-09-21: tl_member.groups ist eine
        // Mehrfachauswahl, und die Reihenfolge der Haken darin ist kein
        // Preisargument. Also der günstigste — hier steht die teurere Zeile
        // absichtlich zuerst, damit „die erste gewinnt" auffliegen würde.
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
        // Dieselben Zeilen, die Gruppen andersherum gefragt. Eine Auflösung,
        // die von der Eingabereihenfolge abhinge, gäbe hier 9.0000.
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
         * ⚠️ Hier stand bis zum Abschlussreview C-4 die Erwartung 9.0000, mit
         * der Begründung „erst die Schwelle, dann der Preis". Die Begründung
         * trägt innerhalb EINER Gruppe, wo der Betreiber beide Zeilen pflegt —
         * über Gruppen hinweg pflegt niemand die Schnittmenge.
         *
         * Gruppe 5 flach 8,00 ab 1, Gruppe 7 dann 9,00 ab 10. Ein Mitglied
         * beider zahlte bei 9 Stück 8,00 und bei 10 Stück 9,00: Die zweite
         * Mitgliedschaft war eine Preiserhöhung, und der Test schrieb sie fest.
         */
        $calculator = $this->calculatorWith([
            new PriceRow('8.0000', 'EUR', 5, '1.0000'),
            new PriceRow('9.0000', 'EUR', 7, '10.0000'),
        ]);

        self::assertSame('8.0000', $calculator->calculate(1, 'EUR', [5, 7], '10', 1_757_000_000)->net);
        // Und ohne die zweite Gruppe derselbe Preis — das ist der Punkt.
        self::assertSame('8.0000', $calculator->calculate(1, 'EUR', [5], '10', 1_757_000_000)->net);
    }

    public function testInnerhalbEinerGruppeSchlaegtDieHoehereSchwelleWeiterhin(): void
    {
        // Die Regel von oben, unangetastet: Wo der Betreiber beide Zeilen
        // pflegt, ist die Staffel eine Aussage und kein Zufall. Sonst wäre
        // eine Staffel, die den Preis anhebt, wirkungslos.
        $calculator = $this->calculatorWith([
            new PriceRow('8.0000', 'EUR', 5, '1.0000'),
            new PriceRow('9.0000', 'EUR', 5, '10.0000'),
        ]);

        self::assertSame('9.0000', $calculator->calculate(1, 'EUR', [5], '10', 1_757_000_000)->net);
    }

    public function testDieAufloesungHaengtNichtAnDerZeilenreihenfolge(): void
    {
        /*
         * Der zweite Grund für die Auflösung je Gruppe: Eine gemischte Regel
         * („bei verschiedenen Gruppen der Betrag, sonst die Schwelle") ist
         * nicht transitiv. Dieselben drei Zeilen ergäben dann je nach
         * Reihenfolge der Datenbankausgabe 8,00 oder 9,00 — und welche das ist,
         * entscheidet die Engine.
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

        // 8,50 aus Gruppe 7 — Gruppe 5 löst für sich auf 9,00 auf (Staffel),
        // und über die Gruppen gewinnt der günstigere.
        self::assertSame('8.5000', $expected);
    }

    public function testEineAnwendbareGruppenzeileSchlaegtJedeAllgemeine(): void
    {
        // ⚠️ Dieser Test hieß bis zur zweiten Reviewrunde „die Zeilen für alle
        // gehen in jede Gruppenauflösung ein" — und prüfte damit eine Absicht
        // ohne beobachtbare Wirkung: Eine Gruppenzeile schlägt jede allgemeine,
        // also hätte das Weglassen nichts geändert. Er prüft in Wahrheit das
        // hier, und das ist die Regel aus block-a 5.1.
        $calculator = $this->calculatorWith([
            new PriceRow('10.0000', 'EUR', null, '1.0000'),
            new PriceRow('7.0000', 'EUR', null, '100.0000'),
            new PriceRow('9.0000', 'EUR', 5, '1.0000'),
        ]);

        // Bei 1: die Gruppenzeile, obwohl es keine günstigere allgemeine gibt.
        self::assertSame('9.0000', $calculator->calculate(1, 'EUR', [5], '1', 1_757_000_000)->net);

        /*
         * ⚠️ Bei 100 ebenfalls die Gruppenzeile — 9,00, während ein GAST dort
         * 7,00 zahlt. Das ist die Ordnung aus block-a 5.1 („Spezifität
         * entscheidet, nicht der kleinere Betrag"), und sie ist hier
         * unangetastet geblieben: Ein Mengenrabatt darf einen Gruppenpreis
         * nicht still überschreiben, sonst nennt der Hinweis an den Kunden den
         * falschen Grund.
         *
         * Es ist derselbe Geschmack wie der Befund B2 des C-4-Reviews, nur
         * zwischen Gruppe und Listenpreis statt zwischen zwei Gruppen — und
         * anders als dort eine ausdrücklich getroffene Entscheidung. Ob sie so
         * bleiben soll, ist Michaels Frage, nicht die dieses Tests; er hält
         * nur fest, was heute gilt.
         */
        self::assertSame('9.0000', $calculator->calculate(1, 'EUR', [5], '100', 1_757_000_000)->net);
    }

    public function testZweiZeilenDerselbenGruppeBleibenUnentschieden(): void
    {
        // ⚠️ Die Grenze der Regel oben, und sie ist der Punkt: Zwei Zeilen
        // EINER Gruppe auf einer Schwelle sind ein Gleichstand, den
        // DbalPriceTieIssueSource dem Betreiber meldet. Würde hier still die
        // günstigere gewählt, verschwände der Befund aus der Welt — der Shop
        // verkaufte zu einem Preis aus einer Doppelzeile, von der niemand mehr
        // erfährt.
        //
        // Geprüft wird deshalb, dass die ERSTE gewinnt, so wie vor C-4: die
        // Zeile, die die Datenbank zuerst liefert.
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
