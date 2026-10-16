<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Document;

/**
 * The payment term of a purchase on account (E-2, Z1): the day the invoice is
 * payable by, counted in CALENDAR days from the day of issue — a term of 14
 * days is two weeks, weekends included, unlike the delivery period, which
 * counts working days (DeliveryPeriod).
 *
 * Returns the date for the snapshot and the last second of that day for the
 * overdue note: payable BY the 12th means the 12th still counts.
 */
final class PaymentTerm
{
    /**
     * @return array{date: string, endsAt: int}
     */
    public static function of(int $issuedAt, int $days): array
    {
        $day = (new \DateTimeImmutable('@'.$issuedAt))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->modify(\sprintf('+%d days', max(0, $days)))
        ;

        return [
            'date' => $day->format('Y-m-d'),
            'endsAt' => $day->setTime(23, 59, 59)->getTimestamp(),
        ];
    }
}
