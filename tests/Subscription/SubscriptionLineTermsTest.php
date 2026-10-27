<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Pricing\MoneyFormatter;
use Webwerkwien\ContaoConshoBundle\Subscription\RenewalKind;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionLineTerms;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionOffers;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRule;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionTerms;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\ContaoLikeTranslator;

require_once __DIR__.'/../Notification/OrderConfirmationTest.php';

/**
 * Rule 5 monthly subscription, "continues, cancellable at any time"; rule 9 a
 * legacy rule from before variant A (cancellable at any time, but one payment
 * per term); rule 11 yearly subscription "full minimum term".
 */
class SubscriptionLineTermsTest extends TestCase
{
    public function testEinmalkaufHatKeineBedingungen(): void
    {
        self::assertNull($this->terms()->of(0, '80.0000', 'EUR', 'de'));
    }

    public function testMonatsaboNenntSeinenZeilenbetrag(): void
    {
        self::assertSame(
            '2,40 € / Monat · Laufzeit 1 Jahr · danach unbefristet, jederzeit mit 14 Tagen Frist kündbar',
            self::plain($this->terms()->of(5, '2.4000', 'EUR', 'de')),
        );
    }

    /**
     * Variant A: no monthly price any more. A legacy rule without
     * monthly payment ends — that is what the sentence says, and what the transition does.
     */
    public function testEineAltregelOhneMonatlicheZahlungEndet(): void
    {
        self::assertSame(
            '24,00 € einmalig · Laufzeit 1 Jahr · endet danach',
            self::plain($this->terms()->of(9, '24.0000', 'EUR', 'de')),
        );
    }

    public function testJahresaboVerlaengertUmDieVolleLaufzeit(): void
    {
        self::assertStringContainsString('24,00 € einmalig · Laufzeit 1 Jahr', self::plain($this->terms()->of(11, '24.0000', 'EUR', 'de')));
        self::assertStringNotContainsString('endet danach', self::plain($this->terms()->of(11, '24.0000', 'EUR', 'de')));
    }

    public function testEineGeloeschteRegelHatKeineBedingungen(): void
    {
        self::assertNull($this->terms()->of(42, '1.0000', 'EUR', 'de'));
    }

    /**
     * NumberFormatter puts a non-breaking space before the currency sign.
     */
    private static function plain(string|null $text): string
    {
        return str_replace(["\u{a0}", "\u{202f}"], ' ', (string) $text);
    }

    private function terms(): SubscriptionLineTerms
    {
        $rules = [
            5 => new SubscriptionRule(5, 'Monatsabo', 1, 'year', 1, RenewalKind::Indefinite, 14, 30, 14, []),
            9 => new SubscriptionRule(9, 'Altregel', 1, 'year', 0, RenewalKind::Indefinite, 14, 30, 14, []),
            11 => new SubscriptionRule(11, 'Jahresabo', 1, 'year', 0, RenewalKind::SameTerm, 14, 30, 14, []),
        ];

        $offers = $this->createMock(SubscriptionOffers::class);
        $offers->method('rulesByIds')->willReturnCallback(
            static fn (array $ids): array => array_intersect_key($rules, array_flip($ids)),
        );

        return new SubscriptionLineTerms($offers, new SubscriptionTerms(new ContaoLikeTranslator()), new MoneyFormatter());
    }
}
