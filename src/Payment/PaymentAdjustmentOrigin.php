<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Payment;

/**
 * Where an amount on a payment method comes from (C5). A fee for using a
 * payment instrument is unlawful in AT (§ 56 Abs 3 ZaDiG 2018) and for most
 * instruments in DE — the origin lets a finding name it later.
 */
enum PaymentAdjustmentOrigin: string
{
    case Discount = 'discount';
    case Fee = 'fee';
}
