<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Withdrawal;

/**
 * Whether the withdrawal function of an order is open (spec D 6.2, W4).
 *
 * The function has to be available for the WHOLE period (§ 356a BGB,
 * § 13a FAGG), and the period runs from receipt of the goods — with part
 * deliveries, of the last of them. Consho knows neither receipt nor the
 * customer's calendar, only when it shipped. So the window is generous by
 * construction:
 *
 *  - open for as long as any goods are unshipped;
 *  - after that until the END of the day of last shipment + period + grace,
 *    in the installation's time zone.
 *
 * A day too late costs the operator nothing: what arrives is recorded and the
 * operator judges it (D-3). A day too early is a breach of the law.
 *
 * Closed without a date in two cases: the shop set the period to 0 (no
 * withdrawal function at all), and a cancelled order that never shipped —
 * the contract has already ended (decision E7).
 */
final class WithdrawalWindow
{
    private function __construct(
        public readonly WindowState $state,
        public readonly int|null $closesAt,
    ) {
    }

    /**
     * @param int|null $lastShipment the latest shipment of a reported delivery, null when none
     */
    public static function of(
        int $days,
        int $graceDays,
        bool $cancelled,
        bool $anyShipped,
        bool $allShipped,
        int|null $lastShipment,
        \DateTimeZone $zone,
    ): self {
        if ($days <= 0) {
            return new self(WindowState::Off, null);
        }

        if ($cancelled && !$anyShipped) {
            return new self(WindowState::Cancelled, null);
        }

        if (!$allShipped || null === $lastShipment) {
            return new self(WindowState::Open, null);
        }

        $closes = (new \DateTimeImmutable('@'.$lastShipment))
            ->setTimezone($zone)
            ->modify(\sprintf('+%d days', $days + max(0, $graceDays)))
            ->setTime(23, 59, 59)
        ;

        return new self(WindowState::Open, $closes->getTimestamp());
    }

    public function isOpenAt(int $now): bool
    {
        return WindowState::Open === $this->state && (null === $this->closesAt || $now <= $this->closesAt);
    }

    /**
     * Closed by TIME alone (WA, B1): the window had a withdrawal function and
     * its end has passed. That end is an estimate from the shipping date; a
     * parcel that took longer than the grace can still be inside the period,
     * so such a declaration is stored with a mark instead of refused. The
     * other closed states — no function (Off), cancelled before shipping —
     * are no estimate and stay refusals.
     */
    public function isLateAt(int $now): bool
    {
        return WindowState::Open === $this->state && null !== $this->closesAt && $now > $this->closesAt;
    }
}
