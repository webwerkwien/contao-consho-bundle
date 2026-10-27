<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Subscription\RenewalKind;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRule;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionTerms;
use Webwerkwien\ContaoConshoBundle\Tests\Notification\ContaoLikeTranslator;

require_once __DIR__.'/../Notification/OrderConfirmationTest.php';

/**
 * Against the real language files: the sentences are the result here, not a
 * by-product — a missing key would otherwise appear as
 * "CONSHO.subscription.ends" to the customer.
 */
class SubscriptionTermsTest extends TestCase
{
    /**
     * @return iterable<string, array{SubscriptionRule, string, string}>
     */
    public static function cases(): iterable
    {
        yield 'Monatsabo, danach unbefristet (A10)' => [
            self::rule(1, 'year', 1, RenewalKind::Indefinite, 14),
            '1,00 €',
            '1,00 € / Monat · Laufzeit 1 Jahr · danach unbefristet, jederzeit mit 14 Tagen Frist kündbar',
        ];
        yield 'eine Zahlung, endet' => [
            self::rule(1, 'year', 0, RenewalKind::Ends, 14),
            '10,00 €',
            '10,00 € einmalig · Laufzeit 1 Jahr · endet danach',
        ];
        yield 'jährlich abgerechnet' => [
            self::rule(1, 'year', 12, RenewalKind::Ends, 14),
            '10,00 €',
            '10,00 € / Jahr · Laufzeit 1 Jahr · endet danach',
        ];
        yield 'vierteljährlich, gleiche Laufzeit' => [
            self::rule(2, 'year', 3, RenewalKind::SameTerm, 30),
            '27,00 €',
            '27,00 € / 3 Monate · Laufzeit 2 Jahre · verlängert sich um jeweils 2 Jahre, Kündigungsfrist 30 Tage',
        ];
        // Variant A: no more conversion; a legacy rule ends.
        yield 'Altregel jederzeit kündbar ohne monatliche Zahlung endet' => [
            self::rule(1, 'year', 0, RenewalKind::Indefinite, 14),
            '10,00 €',
            '10,00 € einmalig · Laufzeit 1 Jahr · endet danach',
        ];
        yield 'Monate' => [
            self::rule(6, 'month', 1, RenewalKind::Ends, 14),
            '1,00 €',
            '1,00 € / Monat · Laufzeit 6 Monate · endet danach',
        ];
        yield 'ein Tag Frist' => [
            self::rule(1, 'month', 1, RenewalKind::Indefinite, 1),
            '1,00 €',
            '1,00 € / Monat · Laufzeit 1 Monat · danach unbefristet, jederzeit mit 1 Tag Frist kündbar',
        ];
        yield 'ein Tag Kündigungsfrist' => [
            self::rule(1, 'month', 1, RenewalKind::SameTerm, 1),
            '1,00 €',
            '1,00 € / Monat · Laufzeit 1 Monat · verlängert sich um jeweils 1 Monat, Kündigungsfrist 1 Tag',
        ];
    }

    #[DataProvider('cases')]
    public function testDeutsch(SubscriptionRule $rule, string $amount, string $expected): void
    {
        self::assertSame($expected, (new SubscriptionTerms(new ContaoLikeTranslator()))->of($rule, $amount, 'de'));
    }

    public function testEnglisch(): void
    {
        self::assertSame(
            '€1.00 / month · Term 1 year · then open-ended, cancellable at any time with 14 days\' notice',
            (new SubscriptionTerms(new ContaoLikeTranslator()))->of(self::rule(1, 'year', 1, RenewalKind::Indefinite, 14), '€1.00', 'en'),
        );
    }

    /** Abo-2b, P88: the tile takes only the first part — amount and rhythm. */
    public function testNurDerBetragJeZeitraum(): void
    {
        $terms = new SubscriptionTerms(new ContaoLikeTranslator());

        self::assertSame('1,20 € / Monat', $terms->perPeriod(self::rule(1, 'year', 1, RenewalKind::Indefinite, 14), '1,20 €', 'de'));
        self::assertSame('12,00 € / Jahr', $terms->perPeriod(self::rule(1, 'year', 12, RenewalKind::Ends, 14), '12,00 €', 'de'));
        self::assertSame('€1.20 / month', $terms->perPeriod(self::rule(1, 'year', 1, RenewalKind::Indefinite, 14), '€1.20', 'en'));
    }

    private static function rule(int $length, string $unit, int $interval, RenewalKind $renewal, int $notice): SubscriptionRule
    {
        return new SubscriptionRule(1, 'Regel', $length, $unit, $interval, $renewal, $notice, 30, 14, []);
    }
}
