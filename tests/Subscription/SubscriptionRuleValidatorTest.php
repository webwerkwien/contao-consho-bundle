<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Tests\Subscription;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webwerkwien\ContaoConshoBundle\Subscription\SubscriptionRuleValidator;

class SubscriptionRuleValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{array<string, mixed>, list<string>}>
     */
    public static function rules(): iterable
    {
        // Michaels zwei Modelle (Spec, Zitat vor A1).
        yield 'Jahresabo, eine Zahlung' => [self::rule(billingInterval: 0), []];
        yield 'Monatsabo über ein Jahr, unbefristet danach' => [self::rule(billingInterval: 1, renewal: 'indefinite'), []];

        // P4: jede Periode gleich lang.
        yield 'Quartal über ein Jahr' => [self::rule(billingInterval: 3), []];
        yield 'Intervall teilt die Laufzeit nicht' => [self::rule(billingInterval: 5), ['billingInterval']];
        yield 'Intervall länger als die Laufzeit' => [self::rule(termLength: 6, termUnit: 'month', billingInterval: 12), ['billingInterval']];
        yield 'Intervall gleich der Laufzeit' => [self::rule(termLength: 6, termUnit: 'month', billingInterval: 6), []];

        // P3: § 309 Nr. 9 b BGB.
        yield 'Unbefristet mit 28 Tagen' => [self::rule(billingInterval: 1, renewal: 'indefinite', noticeDays: 28, reminderDays: 60), []];
        yield 'Unbefristet mit 29 Tagen' => [self::rule(billingInterval: 1, renewal: 'indefinite', noticeDays: 29, reminderDays: 60), ['noticeDays']];
        yield 'Gleiche Laufzeit mit 90 Tagen' => [self::rule(renewal: 'sameTerm', noticeDays: 90, reminderDays: 120), []];

        // Variante A (2026-10-08): „jederzeit kündbar" nur mit monatlicher Zahlung.
        yield 'Unbefristet mit einer Zahlung je Laufzeit' => [self::rule(billingInterval: 0, renewal: 'indefinite'), ['billingInterval']];
        yield 'Unbefristet jährlich' => [self::rule(billingInterval: 12, renewal: 'indefinite'), ['billingInterval']];
        yield 'Unbefristet vierteljährlich' => [self::rule(billingInterval: 3, renewal: 'indefinite'), ['billingInterval']];
        yield 'Gleiche Laufzeit mit einer Zahlung' => [self::rule(billingInterval: 0, renewal: 'sameTerm'), []];

        // P5: § 6 Abs. 1 Z 2 KSchG.
        yield 'Erinnerung gleichzeitig mit Fristbeginn' => [self::rule(renewal: 'sameTerm', noticeDays: 30, reminderDays: 30), ['reminderDays']];
        yield 'Erinnerung nach Fristbeginn' => [self::rule(billingInterval: 1, renewal: 'indefinite', noticeDays: 14, reminderDays: 7), ['reminderDays']];
        yield 'Endet: keine Frist zu wahren' => [self::rule(renewal: 'ends', noticeDays: 90, reminderDays: 10), []];

        // Abo pausieren, P103: ganze Perioden.
        yield 'Pause eine Periode' => [self::rule(billingInterval: 1, renewal: 'indefinite', reminderDays: 30, pauseMonths: 1), []];
        yield 'Pause kürzer als das Quartal' => [self::rule(billingInterval: 3, pauseMonths: 2), ['pauseMonths']];
        yield 'Pause bei einer Zahlung je Laufzeit wird nicht geprüft' => [self::rule(billingInterval: 0, pauseMonths: 2), []];

        yield 'Mehrere Fehler' => [self::rule(billingInterval: 7, renewal: 'indefinite', noticeDays: 40, reminderDays: 30), ['billingInterval', 'noticeDays', 'reminderDays']];
    }

    /**
     * @param array<string, mixed> $rule
     * @param list<string>         $fields
     */
    #[DataProvider('rules')]
    public function testPrueftDieRegel(array $rule, array $fields): void
    {
        self::assertSame($fields, array_keys((new SubscriptionRuleValidator())->errors($rule)));
    }

    public function testRechnetDieLaufzeitInMonate(): void
    {
        self::assertSame(24, SubscriptionRuleValidator::termMonths(2, 'year'));
        self::assertSame(3, SubscriptionRuleValidator::termMonths(3, 'month'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function rule(int $termLength = 1, string $termUnit = 'year', int $billingInterval = 0, string $renewal = 'ends', int $noticeDays = 14, int $reminderDays = 30, int $pauseMonths = 0): array
    {
        return compact('termLength', 'termUnit', 'billingInterval', 'renewal', 'noticeDays', 'reminderDays', 'pauseMonths');
    }
}
