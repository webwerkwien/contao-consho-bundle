<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Tax;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Catalog\ProductKind;
use Webwerkwien\ContaoConshoBundle\Tax\TaxPlace;

/**
 * Block R (O1, R2): where a supply that travels to the customer is taxed.
 */
class TaxPlaceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, bool, string}>
     */
    public static function faelle(): iterable
    {
        yield 'EU-Ausland, Schalter aus: Shopland' => ['DE', 'AT', false, 'AT'];
        yield 'EU-Ausland, Schalter an: Kundenland' => ['DE', 'AT', true, 'DE'];
        yield 'Inland bleibt Inland' => ['AT', 'AT', false, 'AT'];
        yield 'Drittland, Schalter aus: eingestellter Satz des Landes' => ['CH', 'AT', false, 'CH'];
        yield 'Drittland, Schalter an' => ['CH', 'AT', true, 'CH'];
        yield 'Shop außerhalb der EU kennt keine Lieferschwelle' => ['DE', 'CH', false, 'DE'];
        yield 'Kleinschreibung und Leerzeichen' => [' de', 'at ', false, 'AT'];
        yield 'Ohne Kundenland nichts zu verschieben' => ['', 'AT', false, ''];
        yield 'Ohne Shopland keine Grenze' => ['DE', '', false, 'DE'];
        yield 'Griechenland als ISO-Code' => ['GR', 'AT', false, 'AT'];
    }

    #[DataProvider('faelle')]
    public function testFernverkauf(string $customer, string $shop, bool $atDestination, string $expected): void
    {
        self::assertSame($expected, TaxPlace::forDistanceSale($customer, $shop, $atDestination));
    }

    public function testSiebenundzwanzigMitgliedstaaten(): void
    {
        self::assertCount(27, array_unique(TaxPlace::EU_MEMBER_STATES));
        self::assertFalse(TaxPlace::isEuMemberState('GB'));
        self::assertFalse(TaxPlace::isEuMemberState('EL'));
        self::assertTrue(TaxPlace::isEuMemberState('hr'));
    }

    /**
     * The kind decides first: a service always in the shop's country (DL4),
     * everything else according to the switch.
     */
    public function testDieArtUndDerSchalter(): void
    {
        $land = static fn (ProductKind $kind, bool $schalter): string => $kind->taxCountry('DE', 'AT', $schalter);

        self::assertSame(
            ['physical' => ['AT', 'DE'], 'digital' => ['AT', 'DE'], 'service' => ['AT', 'AT'], 'voucher' => ['AT', 'DE']],
            array_combine(
                array_map(static fn (ProductKind $kind): string => $kind->value, ProductKind::cases()),
                array_map(static fn (ProductKind $kind): array => [$land($kind, false), $land($kind, true)], ProductKind::cases()),
            ),
        );
    }

    /**
     * EZ-2, F5: a single-purpose voucher is the shop's country whatever the
     * switch says; the flag means nothing for any other kind.
     */
    public function testEinEinzweckGutscheinImmerImLandDesShops(): void
    {
        self::assertSame('AT', ProductKind::Voucher->taxCountry('DE', 'AT', true, true));
        self::assertSame('DE', ProductKind::Physical->taxCountry('DE', 'AT', true, true));
        self::assertSame('DE', ProductKind::Digital->taxCountry('DE', 'AT', true, true));
    }
}
