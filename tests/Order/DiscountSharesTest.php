<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Order;

use Brick\Math\BigDecimal;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Order\DiscountShares;

/**
 * Block H (H-5, R1): was jede Warenzeile an den Nachlässen der Bestellung
 * trägt. Zwei Klassen: 1 mit Wanderstock (netto 50) und Rucksack (netto
 * 29,1667), 2 mit Buch (netto 20); dazu Versand, der nie mitzählt.
 */
final class DiscountSharesTest extends TestCase
{
    public function testDerZahlungsnachlassNachDemNettoDerKlasse(): void
    {
        $parts = DiscountShares::of($this->order([
            9 => ['lineType' => 'paymentDiscount', 'taxClass' => '1', 'quantity' => '1.0000', 'taxAmount' => '-1.5833', 'lineTotal' => '-9.5000'],
        ]));

        self::assertSame([1, 2], array_keys($parts), 'nur die Waren der Klasse, kein Versand, kein Buch');
        self::assertSame(['9', '-5.0000', '-6.0000'], $this->flat($parts[1][0]));
        // Der Rest auf die letzte Zeile: zusammen genau die Nachlasszeile.
        self::assertSame(['9', '-2.9167', '-3.5000'], $this->flat($parts[2][0]));
    }

    public function testDerRabattNachDemBeimKaufFestgehaltenenAnteil(): void
    {
        // Die Regel galt nur für den Rucksack: er trägt alles, der Wanderstock nichts.
        $parts = DiscountShares::of($this->order([
            9 => ['lineType' => 'discount', 'taxClass' => '1', 'quantity' => '1.0000', 'taxAmount' => '-0.5833', 'lineTotal' => '-3.5000'],
        ], [2 => '2.9167']));

        self::assertSame([2], array_keys($parts));
        self::assertSame(['9', '-2.9167', '-3.5000'], $this->flat($parts[2][0]));
    }

    public function testOhneFestgehaltenenAnteilWieDerZahlungsnachlass(): void
    {
        $parts = DiscountShares::of($this->order([
            9 => ['lineType' => 'discount', 'taxClass' => '1', 'quantity' => '1.0000', 'taxAmount' => '-1.5833', 'lineTotal' => '-9.5000'],
        ]));

        self::assertSame(['9', '-5.0000', '-6.0000'], $this->flat($parts[1][0]));
    }

    public function testJedeKlasseFuerSichUndBeideNachlaesse(): void
    {
        $parts = DiscountShares::of($this->order([
            8 => ['lineType' => 'discount', 'taxClass' => '2', 'quantity' => '1.0000', 'taxAmount' => '-0.2000', 'lineTotal' => '-2.2000'],
            9 => ['lineType' => 'paymentDiscount', 'taxClass' => '2', 'quantity' => '1.0000', 'taxAmount' => '-0.1000', 'lineTotal' => '-1.1000'],
        ], [3 => '2.0000']));

        self::assertSame([3], array_keys($parts));
        self::assertSame([['8', '-2.0000', '-2.2000'], ['9', '-1.0000', '-1.1000']], array_map($this->flat(...), $parts[3]));
    }

    /**
     * Review H, S1: seither je Klasse und Land eine Nachlasszeile — sie gibt
     * nur an die Waren ihres Landes zurück. Wanderstock DE, Rucksack (eine
     * Beratung) AT, beide Klasse 1.
     */
    public function testJedeNachlasszeileNurAnDieWarenIhresLandes(): void
    {
        $order = $this->order([
            8 => ['lineType' => 'paymentDiscount', 'taxClass' => '1', 'taxCountry' => 'DE', 'quantity' => '1.0000', 'taxAmount' => '-0.9500', 'lineTotal' => '-5.9500'],
            9 => ['lineType' => 'paymentDiscount', 'taxClass' => '1', 'taxCountry' => 'AT', 'quantity' => '1.0000', 'taxAmount' => '-0.5833', 'lineTotal' => '-3.5000'],
        ]);
        $order[1]['taxCountry'] = 'DE';
        $order[2]['taxCountry'] = 'AT';

        $parts = DiscountShares::of($order);

        self::assertSame([['8', '-5.0000', '-5.9500']], array_map($this->flat(...), $parts[1]));
        self::assertSame([['9', '-2.9167', '-3.5000']], array_map($this->flat(...), $parts[2]));
    }

    public function testEineZeileVonFrueherNimmtDieGanzeKlasse(): void
    {
        // Vor S1: eine Zeile je Klasse im Lieferland, auch über die Beratung in AT.
        $order = $this->order([
            9 => ['lineType' => 'paymentDiscount', 'taxClass' => '1', 'taxCountry' => 'DE', 'quantity' => '1.0000', 'taxAmount' => '-1.5833', 'lineTotal' => '-9.5000'],
        ]);
        $order[1]['taxCountry'] = 'DE';
        $order[2]['taxCountry'] = 'AT';

        self::assertSame([1, 2], array_keys(DiscountShares::of($order)));
    }

    public function testDerRestGehtAufDieLetzteZeileNettoWieBrutto(): void
    {
        // −1,00 auf drei gleiche Zeilen: zweimal −0,3333, dann der Rest −0,3334.
        $line = ['lineType' => 'product', 'taxClass' => '5', 'quantity' => '1.0000', 'taxAmount' => '2.0000', 'lineTotal' => '12.0000', 'discountNet' => '0.0000'];
        $parts = DiscountShares::of([1 => $line, 2 => $line, 3 => $line,
            9 => ['lineType' => 'paymentDiscount', 'taxClass' => '5', 'quantity' => '1.0000', 'taxAmount' => '-0.2000', 'lineTotal' => '-1.2000'],
        ]);

        self::assertSame(['9', '-0.3333', '-0.4000'], $this->flat($parts[1][0]));
        self::assertSame(['9', '-0.3334', '-0.4000'], $this->flat($parts[3][0]));
    }

    public function testDieGutschriftIstWasDieZeileGekostetHat(): void
    {
        $order = $this->order([
            9 => ['lineType' => 'paymentDiscount', 'taxClass' => '1', 'quantity' => '1.0000', 'taxAmount' => '-1.5833', 'lineTotal' => '-9.5000'],
        ]);
        $parts = DiscountShares::of($order);

        // 1 von 2 Wanderstöcken: (60 − 6) / 2.
        self::assertSame('27.0000', (string) DiscountShares::credit($order[1], $parts[1], '1'));
        self::assertSame('31.5000', (string) DiscountShares::credit($order[2], $parts[2], BigDecimal::one()));
        // Ohne Nachlass die Zeile selbst.
        self::assertSame('35.0000', (string) DiscountShares::credit($order[2], [], '1'));
        self::assertSame('0', (string) DiscountShares::credit(['quantity' => '0', 'lineTotal' => '5'], [], '1'));
    }

    /**
     * @param array<int, array<string, string>> $reductions
     * @param array<int, string>                $frozen order item id => discountNet
     *
     * @return array<int, array<string, string>>
     */
    private function order(array $reductions, array $frozen = []): array
    {
        $items = [
            1 => ['lineType' => 'product', 'taxClass' => '1', 'quantity' => '2.0000', 'taxAmount' => '10.0000', 'lineTotal' => '60.0000'],
            2 => ['lineType' => 'product', 'taxClass' => '1', 'quantity' => '1.0000', 'taxAmount' => '5.8333', 'lineTotal' => '35.0000'],
            3 => ['lineType' => 'product', 'taxClass' => '2', 'quantity' => '1.0000', 'taxAmount' => '2.0000', 'lineTotal' => '22.0000'],
            4 => ['lineType' => 'shipping', 'taxClass' => '1', 'quantity' => '1.0000', 'taxAmount' => '0.8333', 'lineTotal' => '5.0000'],
        ];

        foreach ($items as $id => $item) {
            $items[$id]['discountNet'] = $frozen[$id] ?? '0.0000';
        }

        return $items + $reductions;
    }

    /**
     * @param array{line: int, net: BigDecimal, gross: BigDecimal} $part
     *
     * @return list<string>
     */
    private function flat(array $part): array
    {
        return [(string) $part['line'], (string) $part['net'], (string) $part['gross']];
    }
}
