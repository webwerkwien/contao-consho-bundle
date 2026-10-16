<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Dashboard;

/**
 * The open work of one shop (DB3). Mutable while OpenWorkReader counts.
 */
final class OpenWork
{
    public int $unpaid = 0;

    public int $overdue = 0;

    public int $toDeliver = 0;

    public int $undecided = 0;

    public int $refundOpen = 0;

    /**
     * The earliest refund deadline, per tile — each names its own records'
     * deadline, 0 for none. One shared value named the other tile's date
     * (acceptance on c5, 2026-10-07).
     */
    public int $undecidedDue = 0;

    public int $refundDue = 0;

    public int $complaints = 0;

    public function isEmpty(): bool
    {
        return 0 === $this->unpaid + $this->toDeliver + $this->undecided + $this->refundOpen + $this->complaints;
    }
}
