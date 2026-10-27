<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Subscription;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The conditions of a subscription line in words (Abo-2, P15; A10's example
 * "10 € / Monat · Mindestlaufzeit 1 Jahr · …").
 *
 * Pure on purpose: every caller hands in the amount it already SHOWS, so the
 * sentence and the price column beside it cannot say two things.
 *
 * "Läuft weiter, jederzeit kündbar" is monthly only since variant A;
 * a rule from before it billed otherwise ends, and the sentence
 * says so. The conversion into the monthly plan's price (A8) is gone.
 */
final class SubscriptionTerms
{
    private const DOMAIN = 'contao_default';

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /**
     * @param string $amount the formatted amount per billing period, as the caller shows it
     */
    public function of(SubscriptionRule $rule, string $amount, string $language): string
    {
        return implode(' · ', [
            $this->amount($rule, $amount, $language),
            $this->t('term', [$this->duration($rule->termLength, $rule->termUnit, $language)], $language),
            $this->renewal($rule, $language),
        ]);
    }

    /**
     * The amount with its billing period alone — "1,20 € / Monat" — as a
     * listing tile prints it for a product sold only as a plan (Abo-2b, P88).
     */
    public function perPeriod(SubscriptionRule $rule, string $amount, string $language): string
    {
        return $this->amount($rule, $amount, $language);
    }

    private function amount(SubscriptionRule $rule, string $amount, string $language): string
    {
        return match ($rule->billingInterval) {
            0 => $this->t('once', [$amount], $language),
            1 => $this->t('perMonth', [$amount], $language),
            12 => $this->t('perYear', [$amount], $language),
            default => $this->t('perMonths', [$amount, (string) $rule->billingInterval], $language),
        };
    }

    private function renewal(SubscriptionRule $rule, string $language): string
    {
        return match ($rule->renewal) {
            RenewalKind::Ends => $this->t('ends', [], $language),
            RenewalKind::SameTerm => $this->t('sameTerm', [
                $this->duration($rule->termLength, $rule->termUnit, $language),
                $this->t(1 === $rule->noticeDays ? 'dayNominative' : 'daysNominative', [(string) $rule->noticeDays], $language),
            ], $language),
            // Monthly only since variant A; a rule from before billed otherwise ends.
            RenewalKind::Indefinite => 1 === $rule->billingInterval
                ? $this->t('indefinite', [$this->notice($rule, $language)], $language)
                : $this->t('ends', [], $language),
        };
    }

    /**
     * The notice after "mit"/"with" — "mit 14 Tagen Frist", "with 14 days' notice".
     */
    private function notice(SubscriptionRule $rule, string $language): string
    {
        return $this->t(1 === $rule->noticeDays ? 'day' : 'days', [(string) $rule->noticeDays], $language);
    }

    private function duration(int $length, string $unit, string $language): string
    {
        $key = 'year' === $unit ? (1 === $length ? 'year' : 'years') : (1 === $length ? 'month' : 'months');

        return $this->t($key, [(string) $length], $language);
    }

    /**
     * @param list<string> $args
     */
    private function t(string $key, array $args, string $language): string
    {
        return $this->translator->trans('CONSHO.subscription.'.$key, $args, self::DOMAIN, $language);
    }
}
