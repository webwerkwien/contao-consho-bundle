<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

/**
 * What a subscription rule must not say (Abo-1, plan P3–P5).
 *
 * Pure: it takes the rule's values and names the fields in error, so the
 * checks can be tested without a mask. SubscriptionRuleListener asks it before
 * a rule is saved.
 */
final class SubscriptionRuleValidator
{
    /**
     * § 309 Nr. 9 b BGB: after the minimum term only "jederzeit mit einer Frist
     * von höchstens einem Monat". 28 days are at most one month in every month
     * of the year; 30 would be more than February (plan P3).
     */
    public const MAX_INDEFINITE_NOTICE_DAYS = 28;

    public static function termMonths(int $length, string $unit): int
    {
        return 'year' === $unit ? $length * 12 : $length;
    }

    /**
     * @param array<string, mixed> $rule
     *
     * @return array<string, string> field => key under tl_consho_subscription_rule.errors
     */
    public function errors(array $rule): array
    {
        $errors = [];
        $months = self::termMonths((int) ($rule['termLength'] ?? 0), (string) ($rule['termUnit'] ?? ''));
        $interval = (int) ($rule['billingInterval'] ?? 0);
        $renewal = RenewalKind::tryFrom((string) ($rule['renewal'] ?? '')) ?? RenewalKind::Ends;
        $notice = (int) ($rule['noticeDays'] ?? 0);

        // Every period the same length, the last one included (plan P4).
        if ($interval > 0 && ($interval > $months || 0 !== $months % $interval)) {
            $errors['billingInterval'] = 'intervalDividesTerm';
        }

        // Whole periods (P103): a pause shorter than one could never be taken.
        // A rule with one payment per term is not checked — it is never paused
        // (P103).
        $pause = (int) ($rule['pauseMonths'] ?? 0);

        if ($pause > 0 && $interval > 0 && $pause < $interval) {
            $errors['pauseMonths'] = 'pauseShorterThanPeriod';
        }

        if (RenewalKind::Indefinite === $renewal && $notice > self::MAX_INDEFINITE_NOTICE_DAYS) {
            $errors['noticeDays'] = 'noticeTooLong';
        }

        /*
         * Variant A (Michael, 2026-10-08: „passt so"): "läuft weiter,
         * jederzeit kündbar" only with monthly payment. Paid for a year ahead
         * and cancellable within 28 days, every notice after the minimum term
         * would owe a refund — the conversion into the monthly plan's price
         * that solved it is gone.
         */
        if (RenewalKind::Indefinite === $renewal && 1 !== $interval) {
            $errors['billingInterval'] = 'indefiniteMonthlyOnly';
        }

        /*
         * § 6 Abs. 1 Z 2 KSchG: silence counts as consent to the renewal only
         * if the customer is told "bei Beginn der hiefür vorgesehenen Frist"
         * and has a reasonable time to object. The reminder is that notice, so
         * it has to go out before the notice period starts (plan P5).
         */
        if ($renewal->renews() && (int) ($rule['reminderDays'] ?? 0) <= $notice) {
            $errors['reminderDays'] = 'reminderBeforeNotice';
        }

        return $errors;
    }
}
