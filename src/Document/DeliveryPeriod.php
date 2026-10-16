<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

/**
 * The expected delivery period a prepayment invoice states (C-8, Michael
 * 2026-09-24: "Lieferzeit-Spanne am Shop").
 *
 * Counted in working days from the day of issue, Monday to Friday. Public
 * holidays are NOT taken out: which ones apply depends on the country and in
 * Germany on the state, and a calendar the bundle shipped would be wrong for
 * the next shop — the same reason Consho sets no legal texts. The period is
 * an expectation the operator states, and the operator chooses the numbers.
 *
 * Returned as dates (Y-m-d) for the snapshot: a snapshot keeps the answer,
 * not the rule that produced it.
 */
final class DeliveryPeriod
{
    /**
     * @return array{from: string, to: string}|null null when no delivery time is configured
     */
    public static function of(int $issuedAt, int $minDays, int $maxDays): array|null
    {
        if ($minDays < 1 && $maxDays < 1) {
            return null;
        }

        $min = max(0, $minDays);
        $max = max($min, $maxDays);

        $day = (new \DateTimeImmutable('@'.$issuedAt))->setTimezone(new \DateTimeZone(date_default_timezone_get()));

        return [
            'from' => self::addWorkingDays($day, $min)->format('Y-m-d'),
            'to' => self::addWorkingDays($day, $max)->format('Y-m-d'),
        ];
    }

    /**
     * The delivery of an invoice issued AT SHIPMENT (E-6b): the day of the
     * first parcel — and after a partial one, the day the rest is expected,
     * so many working days later. `kind` tells the invoice which label to use.
     *
     * @return array{from: string, to: string, kind: string}
     */
    public static function shipped(int $shippedAt, bool $complete, int $remainderDays): array
    {
        $day = (new \DateTimeImmutable('@'.$shippedAt))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        $to = $complete || $remainderDays < 1 ? $day : self::addWorkingDays($day, $remainderDays);

        return ['from' => $day->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'kind' => $complete ? 'delivered' : 'partial'];
    }

    private static function addWorkingDays(\DateTimeImmutable $day, int $days): \DateTimeImmutable
    {
        while ($days > 0) {
            $day = $day->modify('+1 day');

            // ISO-8601: 6 Saturday, 7 Sunday.
            if ((int) $day->format('N') < 6) {
                --$days;
            }
        }

        return $day;
    }
}
