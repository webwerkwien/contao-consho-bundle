<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Voucher;

/**
 * Why a voucher's balance changed (block Wertgutscheine, WG9).
 */
enum VoucherBookingType: string
{
    // The value it was issued with — bought (WG-4) or by hand (WG13).
    case Issue = 'issue';
    // Spent on an order (WG-5); negative.
    case Redeem = 'redeem';
    // Back from an order paid with it: cancellation, withdrawal (WG11).
    case Refund = 'refund';
    // The purchase of the voucher itself was withdrawn (WG7); negative.
    case Withdrawal = 'withdrawal';
    // A correction by the operator, with a note (WG13).
    case Manual = 'manual';
}
