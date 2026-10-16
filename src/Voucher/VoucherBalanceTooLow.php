<?php declare(strict_types=1);

namespace Webwerkwien\ContaoConshoBundle\Voucher;

/**
 * A booking would take a voucher below zero — at the purchase click, someone
 * else spent it first (WG-5); in the back end, a correction larger than what
 * is left.
 */
final class VoucherBalanceTooLow extends \RuntimeException
{
    public static function for(int $voucherId, string $balance, string $amount): self
    {
        return new self(\sprintf('Voucher %d holds %s, a booking of %s would take it below zero.', $voucherId, $balance, $amount));
    }
}
